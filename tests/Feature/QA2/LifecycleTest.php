<?php

namespace Tests\Feature\QA2;

use App\Enums\ClientSource;
use App\Enums\LeadStatus;
use App\Enums\ProjectStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Exceptions\RowVersionConflictException;
use App\Filament\Pages\Attention;
use App\Filament\Pages\TaskPlanner;
use App\Filament\Resources\ClientResource;
use App\Filament\Resources\LeadResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\ProjectResource\RelationManagers\StagesRelationManager;
use App\Filament\Resources\TaskResource;
use App\Filament\Widgets\MyTasksWidget;
use App\Models\BudgetLine;
use App\Models\Client;
use App\Models\ClientUser;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\StageTemplateItem;
use App\Models\Task;
use App\Models\User;
use App\Services\Projects\ReadinessService;
use App\Services\Stages\StageTemplateService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StageTemplateSeeder;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Layihə həyat dövrü — modul dərinliyi: müştəri → layihə → mərhələ → tapşırıq →
 * hazırlıq faizi.
 *
 * Burada hər törəmə rəqəm ƏLLƏ hesablanıb yazılır (məs. «(50*3 + 0*1)/4 = 38»):
 * servis öz düsturu ilə yoxlanılsa, düsturun özündəki səhv görünməz qalır.
 * Ssenari hekayəsi `Scenarios/ClientJourneyScenarioTest.php`-dədir — bu fayl
 * qəsdən modul səviyyəsində qalır.
 */
class LifecycleTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        AccessMatrix::flushCache();

        $this->alfa = StudioWorld::make('alfa');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    // ── Köməkçilər ───────────────────────────────────────────────────────────

    private function project(string $name, array $attributes = []): Project
    {
        return app(TenantContext::class)->actingAs(
            $this->alfa->tenant->id,
            fn () => Project::create(array_merge([
                'client_id' => $this->alfa->client->id,
                'name' => $name,
                'type' => 'apartment',
                'status' => ProjectStatus::Active->value,
                'manager_user_id' => $this->alfa->user('project_manager')->id,
            ], $attributes)),
        );
    }

    /** Mərhələ həmişə layihənin studiyası kontekstində yaradılır — `tenant_id` düşsün. */
    private function stage(Project $project, string $name, int $weight, int $position, StageStatus $status = StageStatus::InProgress): Stage
    {
        return app(TenantContext::class)->actingAs($project->tenant_id, fn () => $project->stages()->create([
            'name' => $name,
            'position' => $position,
            'weight' => $weight,
            'status' => $status->value,
        ]));
    }

    private function task(Stage $stage, string $title, TaskStatus $status = TaskStatus::Todo): Task
    {
        return app(TenantContext::class)->actingAs($stage->tenant_id, fn () => Task::create([
            'project_id' => $stage->project_id,
            'stage_id' => $stage->id,
            'title' => $title,
            'status' => $status->value,
        ]));
    }

    /** Tapşırıq istənilən layihədə — layihənin studiyası kontekstində yaradılır. */
    private function taskIn(Project $project, string $title, array $attributes = []): Task
    {
        return app(TenantContext::class)->actingAs($project->tenant_id, function () use ($project, $title, $attributes) {
            $stage = Stage::firstOrCreate(
                ['project_id' => $project->id, 'position' => 1],
                ['name' => 'Eskiz', 'weight' => 1, 'status' => StageStatus::InProgress->value],
            );

            return Task::create(array_merge([
                'project_id' => $project->id,
                'stage_id' => $stage->id,
                'title' => $title,
                'status' => TaskStatus::Todo->value,
            ], $attributes));
        });
    }

    private function enterStudio(StudioWorld $world): void
    {
        app(TenantContext::class)->set($world->tenant->id);
        Filament::setTenant(null, true);
    }

    private function asStaff(string $role): User
    {
        $user = $this->alfa->user($role);
        $this->actingAs($user);
        AccessMatrix::flushCache();

        return $user;
    }

    /** @return array<int, string> */
    private function plannerTitles(): array
    {
        $planner = Livewire::test(TaskPlanner::class)->instance();

        return collect($planner->getGroupedTasks())
            ->flatMap(fn ($tasks) => $tasks->pluck('title'))
            ->all();
    }

    /** @return array<int, string> */
    private function widgetTitles(): array
    {
        return Livewire::test(MyTasksWidget::class)
            ->instance()
            ->getTable()
            ->getQuery()
            ->pluck('title')
            ->all();
    }

    /** @return array<string, mixed> */
    private function attentionBlock(string $key): array
    {
        return collect(app(Attention::class)->blocks())->firstWhere('key', $key);
    }

    // =====================================================================
    // 1. MƏRHƏLƏ ŞABLONU — sıra, müddət, çəki
    // =====================================================================

    /**
     * Şablon tətbiq ediləndə mərhələlər sıra, plan tarixləri və ÇƏKİ ilə açılır.
     * Çəki heç vaxt 0 olmamalıdır: 60 günlük «Müəllif nəzarəti» 3 günlük
     * «Təhvil» ilə eyni pay alsa, hazırlıq faizi işin həcmini yox, mərhələ
     * sayını göstərir.
     */
    public function test_template_creates_ordered_stages_with_nonzero_duration_weights(): void
    {
        $this->seed(StageTemplateSeeder::class);
        $template = StageTemplate::where('key', 'complex_project')->firstOrFail();
        $project = $this->project('Çəki layihəsi');

        app(StageTemplateService::class)->apply($project, $template, Carbon::parse('2026-03-02'));

        $stages = $project->stages()->orderBy('position')->get();

        $this->assertSame($template->items->count(), $stages->count(), 'Şablonun hər bəndi bir mərhələ açır.');
        $this->assertSame(range(1, $stages->count()), $stages->pluck('position')->map(fn ($p) => (int) $p)->all(), 'Sıra 1-dən ardıcıl olmalıdır.');

        // Tarixlər ardıcıldır: 7 günlük Briefinq 02.03 → 09.03, növbəti 10.03.
        $this->assertSame('2026-03-02', $stages[0]->date_plan_start->toDateString());
        $this->assertSame('2026-03-09', $stages[0]->date_plan_end->toDateString());
        $this->assertSame('2026-03-10', $stages[1]->date_plan_start->toDateString());

        // Çəki = plan müddəti; heç bir mərhələ 0 çəki ilə açılmır.
        $this->assertSame(0, $stages->where('weight', 0)->count(), 'Çəki 0 ola bilməz — mərhələ hesaba düşməz.');
        $this->assertSame(60, (int) $stages->firstWhere('name', 'Müəllif nəzarəti')->weight);
        $this->assertSame(3, (int) $stages->firstWhere('name', 'Təhvil')->weight);
        $this->assertGreaterThan(1, $stages->pluck('weight')->unique()->count(), 'Bütün çəkilər eyni olmamalıdır.');
    }

    /** Müddəti verilməyən bənd çəkini 0-a salmır — 1 qalır (köhnə davranış). */
    public function test_template_item_without_duration_still_weighs_one(): void
    {
        $template = StageTemplate::create([
            'key' => 'undated_lifecycle',
            'name' => ['az' => 'Müddətsiz'],
            'position' => 90,
            'active' => true,
        ]);
        $template->items()->create(['name' => ['az' => 'Müddətsiz mərhələ'], 'position' => 0, 'default_duration_days' => null]);

        $project = $this->project('Müddətsiz layihə');
        app(StageTemplateService::class)->apply($project, $template->fresh());

        $this->assertSame([1], $project->stages()->pluck('weight')->map(fn ($w) => (int) $w)->unique()->values()->all());
        $this->assertNull($project->stages()->first()->date_plan_end, 'Müddət yoxdursa plan tarixi də qurulmur.');
    }

    /**
     * QA TAPINTI: «Şablon tətbiq et» düyməsi iki dəfə basılanda mərhələ planı
     * İKİQATLANIRDI. Düymənin öz şərhi «Rewrites the project's whole stage plan»
     * deyirdi, servis isə yalnız sonuna əlavə edirdi — operator üçün heç bir
     * fərqlənən nəticə görünmür (bildiriş hər iki halda «tətbiq edildi»), ona
     * görə ikinci klik adi hadisədir. Nəticədə 8 mərhələli plan 16 sətrə çevrilir
     * və çəkili hazırlıq faizi mənasını itirir (hər mərhələ iki dəfə sayılır).
     */
    public function test_applying_the_same_template_twice_does_not_duplicate_the_plan(): void
    {
        $this->seed(StageTemplateSeeder::class);
        $template = StageTemplate::where('key', 'design_project')->firstOrFail();
        $project = $this->project('Təkrar şablon');

        app(StageTemplateService::class)->apply($project, $template, Carbon::parse('2026-01-01'));
        $first = $project->stages()->pluck('name')->all();

        app(StageTemplateService::class)->apply($project, $template, Carbon::parse('2026-06-01'));

        $this->assertSame(
            $template->items->count(),
            $project->stages()->count(),
            'Şablonun ikinci tətbiqi mövcud mərhələləri təkrarlamamalıdır.'
        );
        $this->assertSame($first, $project->stages()->orderBy('position')->pluck('name')->all(), 'Mövcud plan olduğu kimi qalır.');
    }

    /** Fərqli şablon isə əlavə oluna bilər — plana yeni mərhələlər qoşulur. */
    public function test_a_different_template_still_appends_its_stages(): void
    {
        $this->seed(StageTemplateSeeder::class);
        $project = $this->project('İki şablon');

        $design = StageTemplate::where('key', 'design_project')->firstOrFail();
        $supervision = StageTemplate::where('key', 'author_supervision')->firstOrFail();

        app(StageTemplateService::class)->apply($project, $design, Carbon::parse('2026-01-01'));
        app(StageTemplateService::class)->apply($project, $supervision, Carbon::parse('2026-07-01'));

        // «Müəllif nəzarəti» şablonunun bəndlərindən yalnız adı təkrarlanmayanlar
        // əlavə olunur; «Briefinq» kimi eyni adlı bənd yoxdur, yəni hamısı düşür.
        $this->assertSame(
            $design->items->count() + $supervision->items->count(),
            $project->stages()->count(),
            'Başqa şablonun mərhələləri plana əlavə olunmalıdır.'
        );
        $this->assertNotNull($project->stages()->where('name', 'Yekun qəbul')->first());
    }

    /**
     * QA TAPINTI: mərhələ formasındaki «Çəki» sahəsi `maxValue(10)` idi, şablon
     * isə plan müddətini çəki kimi yazır (60, 90 gün). Nəticə: şablondan açılan
     * mərhələni formada AÇIB SAXLAMAQ mümkün deyildi — istənilən dəyişiklik
     * (ad, status, məsul şəxs, tarix) «Çəki 10-dan böyük olmamalıdır»
     * validasiyasına düşürdü. Operatorun yeganə çıxışı çəkini əl ilə azaltmaq,
     * yəni toxunmaq istəmədiyi hazırlıq faizini səssizcə pozmaq olurdu.
     */
    public function test_stage_form_accepts_the_weights_the_template_writes(): void
    {
        $this->seed(StageTemplateSeeder::class);

        $heaviest = StageTemplateItem::max('default_duration_days');
        $this->assertGreaterThan(10, (int) $heaviest, 'Şablonlarda 10-dan böyük müddət yoxdursa test köhnəlib.');

        $schema = (new \ReflectionMethod(StagesRelationManager::class, 'form'))
            ->invoke(
                (new \ReflectionClass(StagesRelationManager::class))->newInstanceWithoutConstructor(),
                new Schema,
            );

        $weight = collect($schema->getComponents())
            ->first(fn ($component) => method_exists($component, 'getName') && $component->getName() === 'weight');

        $this->assertNotNull($weight, 'Mərhələ formasında «Çəki» sahəsi tapılmadı — test köhnəlib.');
        $this->assertGreaterThanOrEqual(
            (int) $heaviest,
            (int) $weight->getMaxValue(),
            'Forma şablonun yazdığı çəkini qəbul etməlidir, əks halda mərhələ redaktə edilə bilmir.'
        );
    }

    // =====================================================================
    // 2. HAZIRLIQ FAİZİ — arifmetika əllə yoxlanılır
    // =====================================================================

    /**
     * Mərhələ faizi öz tapşırıqlarından, layihə faizi isə mərhələ ÇƏKİLƏRİ ilə
     * ölçülən ortadan gəlir. Rəqəmlər burada əl ilə hesablanıb:
     *   ağır (çəki 3): 4 tapşırıqdan 2-si hazır → 50%
     *   yüngül (çəki 1): 2 tapşırıqdan 0-ı hazır → 0%
     *   layihə: (50*3 + 0*1) / 4 = 37.5 → yuvarlaqlaşma 38
     */
    public function test_project_readiness_is_the_hand_checked_weighted_average(): void
    {
        $project = $this->project('Arifmetika');
        $heavy = $this->stage($project, 'Ağır', weight: 3, position: 1);
        $light = $this->stage($project, 'Yüngül', weight: 1, position: 2);

        $this->task($heavy, 'A1', TaskStatus::Done);
        $this->task($heavy, 'A2', TaskStatus::Done);
        $this->task($heavy, 'A3');
        $this->task($heavy, 'A4');
        $this->task($light, 'B1');
        $this->task($light, 'B2');

        $this->assertSame(50, (int) $heavy->fresh()->readiness, '2/4 = 50%');
        $this->assertSame(0, (int) $light->fresh()->readiness, '0/2 = 0%');
        $this->assertSame(38, (int) $project->fresh()->readiness, '(50*3 + 0*1)/4 = 37.5 → 38');
    }

    /** «Hazır» mərhələ hər iki ekranda 100%-dir — tapşırıqları nə olursa olsun. */
    public function test_a_done_stage_reads_one_hundred_percent_regardless_of_its_tasks(): void
    {
        $project = $this->project('Bitmiş mərhələ');
        $stage = $this->stage($project, 'Eskiz', weight: 2, position: 1);

        $this->task($stage, 'Yarımçıq 1');
        $this->task($stage, 'Yarımçıq 2');
        $this->assertSame(0, (int) $stage->fresh()->readiness);

        $stage->update(['status' => StageStatus::Done->value]);

        // Keşlənmiş mərhələ sütunu da, layihə sütunu da dərhal 100 olmalıdır:
        // iki ekranda iki fərqli rəqəm görünməsi ən çox şikayət olunan hal idi.
        $this->assertSame(100, (int) $stage->fresh()->readiness);
        $this->assertSame(100, (int) $project->fresh()->readiness);
    }

    /**
     * Bütün çəkilər 0 olduqda məxrəc sıfıra düşməməli və faiz itməməlidir —
     * bərabər paya keçilir. İki mərhələ, biri 100%, biri 0% → 50.
     */
    public function test_all_zero_weights_fall_back_to_equal_shares(): void
    {
        $project = $this->project('Sıfır çəki');
        $first = $this->stage($project, 'Birinci', weight: 0, position: 1);
        $second = $this->stage($project, 'İkinci', weight: 0, position: 2);

        $this->task($first, 'Hazır', TaskStatus::Done);
        $this->task($second, 'Gözləyir');

        $this->assertSame(100, (int) $first->fresh()->readiness);
        $this->assertSame(0, (int) $second->fresh()->readiness);
        $this->assertSame(50, (int) $project->fresh()->readiness, 'Çəkilər mənasızdırsa bərabər pay: (100+0)/2 = 50.');
    }

    /** Mərhələsi olmayan layihə 0%-dir — bölmə xətası yox, sakit sıfır. */
    public function test_a_project_without_stages_is_zero_percent(): void
    {
        $project = $this->project('Mərhələsiz');

        app(ReadinessService::class)->recalculateProject($project);

        $this->assertSame(0, (int) $project->fresh()->readiness);
    }

    /** Tapşırıq statusu dəyişən an observer hər iki sütunu yeniləyir. */
    public function test_changing_a_task_status_recalculates_immediately(): void
    {
        $project = $this->project('Dərhal');
        $stage = $this->stage($project, 'Eskiz', weight: 1, position: 1);
        $task = $this->task($stage, 'Plan');

        $this->assertSame(0, (int) $stage->fresh()->readiness);

        $task->update(['status' => TaskStatus::Done->value]);

        $this->assertSame(100, (int) $stage->fresh()->readiness, 'Observer dərhal işləməlidir.');
        $this->assertSame(100, (int) $project->fresh()->readiness);
        $this->assertNotNull($task->fresh()->completed_at, 'Bitmə vaxtı da yazılmalıdır.');

        // Geri qaytarma da simmetrik olmalıdır.
        $task->update(['status' => TaskStatus::InProgress->value]);
        $this->assertSame(0, (int) $stage->fresh()->readiness);
        $this->assertNull($task->fresh()->completed_at);
    }

    /** Tapşırıq başqa mərhələyə köçürüləndə HƏR İKİ mərhələ yenilənir. */
    public function test_moving_a_task_refreshes_both_stages(): void
    {
        $project = $this->project('Köçürmə');
        $from = $this->stage($project, 'Mənbə', weight: 1, position: 1);
        $to = $this->stage($project, 'Hədəf', weight: 1, position: 2);

        $task = $this->task($from, 'Köçən', TaskStatus::Done);
        $this->task($to, 'Qalan');

        $this->assertSame(100, (int) $from->fresh()->readiness);
        $this->assertSame(0, (int) $to->fresh()->readiness);

        $task->update(['stage_id' => $to->id]);

        $this->assertSame(0, (int) $from->fresh()->readiness, 'Mənbə boşaldı → 0%.');
        $this->assertSame(50, (int) $to->fresh()->readiness, 'Hədəfdə 1/2 hazır → 50%.');
    }

    /**
     * QA TAPINTI: LƏĞV EDİLMİŞ tapşırıq məxrəcdə qalırdı, yəni mərhələ bir daha
     * 100%-ə çatmırdı. Ssenari adi haldır: 4 tapşırıqdan biri «artıq lazım
     * deyil» deyə «Ləğv edilib» olur, qalan 3 bağlanır — mərhələ 75% yazır və
     * menecer onu bağlaya bilməyən «yarımçıq» kimi görür. `Ləğv edilib`
     * sistemin hər yerində (Attention, TaskPlanner, isFinal) «görülməyəcək iş»
     * sayılır; hazırlıq hesabı yeganə istisna idi.
     */
    public function test_cancelled_tasks_do_not_hold_a_stage_below_one_hundred(): void
    {
        $project = $this->project('Ləğv');
        $stage = $this->stage($project, 'Eskiz', weight: 1, position: 1);

        $this->task($stage, 'Hazır 1', TaskStatus::Done);
        $this->task($stage, 'Hazır 2', TaskStatus::Done);
        $this->task($stage, 'Hazır 3', TaskStatus::Done);
        $cancelled = $this->task($stage, 'Lazım deyil');

        $this->assertSame(75, (int) $stage->fresh()->readiness, 'Başlanğıc: 3/4 = 75%.');

        $cancelled->update(['status' => TaskStatus::Cancelled->value]);

        $this->assertSame(
            100,
            (int) $stage->fresh()->readiness,
            'Ləğv edilmiş tapşırıq görülməyəcək işdir — məxrəcdə qalmamalıdır.'
        );
        $this->assertSame(100, (int) $project->fresh()->readiness);
    }

    /** Hamısı ləğv edilmişsə görülən iş yoxdur → 0% (mərhələsiz hal ilə eyni). */
    public function test_a_stage_whose_tasks_are_all_cancelled_is_zero_percent(): void
    {
        $project = $this->project('Hamısı ləğv');
        $stage = $this->stage($project, 'Eskiz', weight: 1, position: 1);

        $this->task($stage, 'Bir', TaskStatus::Cancelled);
        $this->task($stage, 'İki', TaskStatus::Cancelled);

        $this->assertSame(0, (int) $stage->fresh()->readiness);
    }

    // =====================================================================
    // 3. TAPŞIRIQ GÖRÜNÜRLÜYÜ — «yalnız öz layihələri» və studiya izolyasiyası
    // =====================================================================

    /**
     * Dizayner (`own_projects_only`) üzvü OLMADIĞI layihənin tapşırığını HEÇ BİR
     * ekranda görməməlidir. Beş səth bir testdə yoxlanılır, çünki sızma adətən
     * bir ekranda düzəldilib digərində qalır — qayda `TaskResource`-da bir yerdə
     * saxlanılır, bu test isə hər istifadəçinin ona sadiq qaldığını təsdiqləyir.
     */
    public function test_own_projects_only_staff_never_sees_a_colleagues_foreign_task(): void
    {
        $mine = $this->taskIn($this->alfa->project, 'Mənim eskizim');
        $foreign = $this->taskIn($this->alfa->otherProject, 'Yad layihənin işi', [
            'assignee_user_id' => $this->alfa->user('visualizer')->id,
            'deadline' => today()->subWeek(),
        ]);

        $this->enterStudio($this->alfa);
        $designer = $this->asStaff('designer');

        // 1) TaskResource siyahısı
        $titles = TaskResource::getEloquentQuery()->pluck('title')->all();
        $this->assertContains($mine->title, $titles);
        $this->assertNotContains($foreign->title, $titles, 'TaskResource yad layihənin tapşırığını göstərməməlidir.');

        // 2) Tapşırıq planı
        $this->assertNotContains($foreign->title, $this->plannerTitles(), 'TaskPlanner sızdırmamalıdır.');
        $this->assertContains($mine->title, $this->plannerTitles());

        // 3) Dashboard vidjeti (öz üzərinə təyin edilmiş iş)
        $foreign->update(['assignee_user_id' => $designer->id]);
        $this->assertNotContains(
            $foreign->title,
            $this->widgetTitles(),
            'Tapşırıq ona TƏYİN edilsə də, layihənin üzvü deyil — vidjetdə görünməməlidir.'
        );

        // 4) Təqvim (HTTP qatı)
        $events = collect($this->getJson(route('calendar.events', [
            'start' => today()->subMonth()->toDateString(),
            'end' => today()->addMonth()->toDateString(),
        ]))->assertOk()->json());
        $this->assertFalse(
            $events->contains(fn (array $e) => str_contains($e['title'] ?? '', $foreign->title)),
            'Təqvim yad layihənin tapşırığını verməməlidir.'
        );

        // 5) «Diqqət tələb edir» — gecikmiş tapşırıq bloku
        $tasksBlock = $this->attentionBlock('tasks');
        $this->assertSame(0, $tasksBlock['count'], 'Dizaynerin gecikmiş yad tapşırığı olmamalıdır.');
    }

    /** Menecer isə öz layihəsində BAŞQASINA verdiyi tapşırığı görməlidir. */
    public function test_a_manager_sees_tasks_they_delegated_inside_their_own_project(): void
    {
        $delegated = $this->taskIn($this->alfa->project, 'Dizaynerin işi', [
            'assignee_user_id' => $this->alfa->user('designer')->id,
        ]);

        $this->enterStudio($this->alfa);
        $this->asStaff('project_manager');

        $this->assertContains(
            $delegated->title,
            TaskResource::getEloquentQuery()->pluck('title')->all(),
            'İcraçı olmaq şərt deyil — layihənin meneceridir.'
        );
    }

    /**
     * SAHİBİN ƏSAS QORXUSU: studiya A-nın tapşırığı studiya B-də heç bir yolla
     * görünməməlidir. Burada B studiyasının SAHİBKARI ilə yoxlanılır — matrisdə
     * onun heç bir «öz layihələri» məhdudiyyəti yoxdur, yəni tək qoruma
     * tenant skopudur.
     */
    public function test_cross_studio_tasks_are_invisible_even_to_the_other_owner(): void
    {
        $beta = StudioWorld::make('beta');

        $alfaTask = $this->taskIn($this->alfa->project, 'Alfa məxfi tapşırığı', [
            'deadline' => today()->subWeek(),
            'assignee_user_id' => $this->alfa->user('designer')->id,
        ]);

        $this->enterStudio($beta);
        $this->actingAs($beta->user('owner'));
        AccessMatrix::flushCache();

        $this->assertNotContains(
            $alfaTask->title,
            TaskResource::getEloquentQuery()->pluck('title')->all(),
            'Başqa studiyanın tapşırığı TaskResource-da görünməməlidir.'
        );
        $this->assertNotContains($alfaTask->title, $this->plannerTitles(), 'TaskPlanner studiyalar arası sızdırmamalıdır.');
        $this->assertSame(0, $this->attentionBlock('tasks')['count'], 'Beta studiyasının gecikmiş tapşırığı yoxdur.');

        // Layihə və müştəri siyahıları da eyni qorumadadır.
        $this->assertNotContains(
            $this->alfa->project->name,
            ProjectResource::getEloquentQuery()->pluck('name')->all(),
        );
        $this->assertNotContains(
            $this->alfa->client->name,
            ClientResource::getEloquentQuery()->pluck('name')->all(),
        );
    }

    /**
     * QA TAPINTI: SİLİNMİŞ layihənin tapşırıqları `TaskResource` siyahısında
     * qalırdı. Layihə soft-delete olunur, tapşırıq isə yox — MyTasksWidget,
     * TaskPlanner, Attention və Calendar hamısı `whereHas('project')` ilə
     * qorunub, resursun özü isə qorunmamışdı. Sahibkar üçün nəticə: siyahıda
     * layihəsi «—» olan sətirlər, açanda isə boş kontekst.
     */
    public function test_tasks_of_a_deleted_project_leave_the_task_list(): void
    {
        $task = $this->taskIn($this->alfa->project, 'Orfan tapşırıq');

        $this->enterStudio($this->alfa);
        $this->actingAs($this->alfa->user('owner'));
        AccessMatrix::flushCache();

        $this->assertContains($task->title, TaskResource::getEloquentQuery()->pluck('title')->all());

        $this->alfa->project->delete();

        $this->assertNotContains(
            $task->title,
            TaskResource::getEloquentQuery()->pluck('title')->all(),
            'Silinmiş layihənin tapşırığı siyahıda qalmamalıdır.'
        );
        // Sətir bazada qalır (tapşırıq soft-delete etmir) — yəni layihə bərpa
        // olunanda iş də geri qayıdır. Yoxlama məhz bunu qeyd edir.
        $this->assertNotNull(Task::find($task->id), 'Tapşırıq silinmir, yalnız gizlənir.');
    }

    /**
     * QA TAPINTI: görünürlük OXUMADA bağlanmışdı, YAZMADA yox. «Yalnız öz
     * layihələri» rolunun (dizayner, Mərhələ/Tapşırıq = Redaktə) qarşısında iki
     * problem vardı:
     *   1) həm TaskPlanner modalında, həm TaskResource formasında «Layihə»
     *      seçimi studiyanın BÜTÜN layihələrini sadalayırdı — üzvü olmadığı
     *      layihələrin adları (müştəri obyektlərinin adları) ona açıq idi;
     *   2) `project_id` Livewire payload-ından gəldiyi üçün o, üzvü OLMADIĞI
     *      layihəyə tapşırıq yaza bilirdi. Yazdığı sətri sonra özü görmür —
     *      yəni yad layihədə izahsız iş peyda olur.
     * TZ §5.20: icazə UI gizlətməsi ilə deyil, serverdə tətbiq olunur.
     */
    public function test_own_projects_only_staff_cannot_pick_or_write_into_a_foreign_project(): void
    {
        $this->enterStudio($this->alfa);
        Filament::setCurrentPanel('app');
        $this->asStaff('designer');

        // 1) Seçim siyahısı yad layihəni sadalamır.
        $options = Livewire::test(TaskPlanner::class)
            ->instance()
            ->getVisibleProjectOptions();

        $this->assertArrayHasKey($this->alfa->project->id, $options, 'Üzv olduğu layihə seçilə bilməlidir.');
        $this->assertArrayNotHasKey(
            $this->alfa->otherProject->id,
            $options,
            'Üzvü olmadığı layihə seçim siyahısına düşməməlidir.'
        );

        // 2) Payload-la birbaşa yazmaq da bağlıdır.
        $foreignStage = app(TenantContext::class)->actingAs(
            $this->alfa->tenant->id,
            fn () => Stage::firstOrCreate(
                ['project_id' => $this->alfa->otherProject->id, 'position' => 1],
                ['name' => 'Yad mərhələ', 'weight' => 1, 'status' => StageStatus::InProgress->value],
            ),
        );

        try {
            Livewire::test(TaskPlanner::class)->callAction('newTask', [
                'title' => 'Yad layihəyə sızan tapşırıq',
                'project_id' => $this->alfa->otherProject->id,
                'stage_id' => $foreignStage->id,
            ]);
        } catch (\Throwable $e) {
            // 403/404 gözlənilir — vacib olan sətrin yaranmamasıdır.
        }

        $this->assertNull(
            Task::where('title', 'Yad layihəyə sızan tapşırıq')->first(),
            'Üzvü olmadığı layihəyə tapşırıq yazıla bilməməlidir.'
        );

        // Öz layihəsinə yazmaq isə pozulmamalıdır.
        Livewire::test(TaskPlanner::class)
            ->callAction('newTask', [
                'title' => 'Öz layihəmə tapşırıq',
                'project_id' => $this->alfa->project->id,
                'stage_id' => $this->alfa->stage->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertNotNull(Task::where('title', 'Öz layihəmə tapşırıq')->first());
    }

    /** Eyni məhdudiyyət TaskResource formasının «Layihə» seçimində də olmalıdır. */
    public function test_the_task_form_only_offers_projects_the_user_may_see(): void
    {
        $this->enterStudio($this->alfa);
        $this->asStaff('designer');

        $options = TaskResource::visibleProjectOptions();

        $this->assertArrayHasKey($this->alfa->project->id, $options);
        $this->assertArrayNotHasKey(
            $this->alfa->otherProject->id,
            $options,
            'Forma yad layihənin adını göstərməməlidir.'
        );

        // Sahibkar üçün məhdudiyyət yoxdur.
        $this->actingAs($this->alfa->user('owner'));
        AccessMatrix::flushCache();
        $this->assertArrayHasKey($this->alfa->otherProject->id, TaskResource::visibleProjectOptions());
    }

    // =====================================================================
    // 4. SON TARİXLƏR — «Diqqət tələb edir» ekranı
    // =====================================================================

    /** Gecikmə şərti: son tarix keçib, status final deyil. Sərhəd günü gecikmə deyil. */
    public function test_attention_counts_only_genuinely_overdue_work(): void
    {
        $overdue = $this->taskIn($this->alfa->project, 'Gecikmiş', ['deadline' => today()->subDay()]);
        $this->taskIn($this->alfa->project, 'Bugünkü', ['deadline' => today()]);
        $this->taskIn($this->alfa->project, 'Gələcək', ['deadline' => today()->addWeek()]);
        $this->taskIn($this->alfa->project, 'Bitmiş', ['deadline' => today()->subMonth(), 'status' => TaskStatus::Done->value]);
        $this->taskIn($this->alfa->project, 'Ləğv', ['deadline' => today()->subMonth(), 'status' => TaskStatus::Cancelled->value]);
        $this->taskIn($this->alfa->project, 'Tarixsiz');

        $this->enterStudio($this->alfa);
        $this->actingAs($this->alfa->user('owner'));
        AccessMatrix::flushCache();

        $block = $this->attentionBlock('tasks');

        $this->assertSame(1, $block['count'], 'Yalnız bir tapşırıq həqiqətən gecikib.');
        $this->assertSame($overdue->title, $block['items'][0]['title']);
        // Model və SQL şərti eyni cavabı verməlidir — iki yerdə iki məntiq olmasın.
        $this->assertTrue($overdue->fresh()->isOverdue());
        $this->assertFalse(Task::where('title', 'Bugünkü')->first()->isOverdue(), 'Bugün gecikmə deyil.');
    }

    /** Arxiv və silinmiş layihənin gecikmiş işi «bu gün nəyə diqqət» deyil. */
    public function test_attention_ignores_archived_and_deleted_projects(): void
    {
        $this->taskIn($this->alfa->project, 'Arxivin gecikmişi', ['deadline' => today()->subWeek()]);

        $this->enterStudio($this->alfa);
        $this->actingAs($this->alfa->user('owner'));
        AccessMatrix::flushCache();

        $this->assertSame(1, $this->attentionBlock('tasks')['count']);

        $this->alfa->project->update(['status' => ProjectStatus::Archived->value]);
        $this->assertSame(0, app(Attention::class)->blocks()[2]['count'], 'Arxiv layihə sayılmamalıdır.');

        $this->alfa->project->update(['status' => ProjectStatus::Active->value]);
        $this->alfa->project->delete();
        $this->assertSame(0, app(Attention::class)->blocks()[2]['count'], 'Silinmiş layihə sayılmamalıdır.');
    }

    /**
     * `tenant_id`-si NULL olan tapşırıq (köhnə məlumat, CLI/queue ilə yaradılmış
     * sətir) ekranı QIRMAMALIDIR. O, studiya skopuna düşmür — yəni studiyada
     * oturan adam onu görmür — amma bu, səssiz itki deyil, qəsdən seçimdir:
     * sahibsiz sətir heç bir studiyaya aid edilə bilməz. Vacib olan budur ki,
     * ekran istisna atmır və sayğac düzgün qalır.
     */
    public function test_a_task_without_a_tenant_neither_crashes_nor_leaks(): void
    {
        // Tenant konteksti olmadan yaradılır → `tenant_id` NULL qalır.
        $orphan = Task::create([
            'project_id' => $this->alfa->project->id,
            'stage_id' => $this->alfa->stage->id,
            'title' => 'Sahibsiz tapşırıq',
            'status' => TaskStatus::Todo->value,
            'deadline' => today()->subWeek(),
        ]);
        $this->assertNull($orphan->tenant_id, 'Test öncəsi şərt: sətir sahibsizdir.');

        $this->enterStudio($this->alfa);
        $this->actingAs($this->alfa->user('owner'));
        AccessMatrix::flushCache();

        // Ekran işləyir (istisna yoxdur) və sahibsiz sətri göstərmir.
        $block = $this->attentionBlock('tasks');
        $this->assertSame(0, $block['count'], 'Sahibsiz sətir studiyanın sayğacına düşməməlidir.');
        $this->assertNotContains(
            $orphan->title,
            TaskResource::getEloquentQuery()->pluck('title')->all(),
        );

        // Studiya kontekstindən kənarda (CLI, növbə, admin əmri) sətir görünür —
        // yəni məlumat itmir, yalnız izolyasiya olunur.
        app(TenantContext::class)->set(null);
        $this->assertNotNull(Task::find($orphan->id));
    }

    // =====================================================================
    // 5. LİD → MÜŞTƏRİ → LAYİHƏ
    // =====================================================================

    /** Konversiya bir dəfə işləyir: ikinci çağırış yeni müştəri yaratmır. */
    public function test_converting_a_lead_twice_creates_only_one_client(): void
    {
        $lead = app(TenantContext::class)->actingAs($this->alfa->tenant->id, fn () => Lead::create([
            'first_name' => 'Elçin',
            'last_name' => 'Məmmədov',
            'phone' => '+994501112233',
            'lead_source' => 'instagram',
            'status' => LeadStatus::New->value,
            'responsible_user_id' => $this->alfa->user('project_manager')->id,
        ]));

        $this->enterStudio($this->alfa);

        $first = LeadResource::convertToClient($lead);
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status, 'Lid «Qazanılıb» olur.');
        $this->assertSame($first->id, $lead->fresh()->client_id, 'İz `leads.client_id`-də saxlanılır.');

        $countAfterFirst = Client::count();

        // Operator statusu əl ilə geri çevirir və düyməni yenidən basır.
        $lead->forceFill(['status' => LeadStatus::Negotiation->value])->save();
        $second = LeadResource::convertToClient($lead->fresh());

        $this->assertFalse($second->wasRecentlyCreated, 'İkinci çağırış mövcud müştərini qaytarır.');
        $this->assertSame($first->id, $second->id);
        $this->assertSame($countAfterFirst, Client::count(), 'İkinci müştəri sətri yaranmamalıdır.');
        $this->assertSame(LeadStatus::Won, $lead->fresh()->status, 'Status həqiqətə uyğun bərpa olunur.');
    }

    /** Layihə yaranan müştəriyə bağlanır və hazırlıq dövrü oradan başlayır. */
    public function test_the_converted_client_carries_into_a_project(): void
    {
        $lead = app(TenantContext::class)->actingAs($this->alfa->tenant->id, fn () => Lead::create([
            'first_name' => 'Nigar',
            'last_name' => 'Əliyeva',
            'lead_source' => 'tiktok-reklam',
            'status' => LeadStatus::New->value,
        ]));

        $this->enterStudio($this->alfa);
        $client = LeadResource::convertToClient($lead);

        // Enum-a uymayan mənbə itmir: `other`-a düşür, orijinal mətn qeyddə qalır.
        $this->assertSame(ClientSource::Other, $client->source);
        $this->assertStringContainsString('tiktok-reklam', (string) $client->notes);

        $project = $this->project('Konversiya layihəsi', ['client_id' => $client->id]);
        $this->assertSame($client->id, $project->client_id);
        $this->assertSame($this->alfa->tenant->id, $project->tenant_id, 'Layihə lidin studiyasına yazılır.');
    }

    /** Arxivlənmiş (soft-delete edilmiş) müştəri heç bir ekranı qırmır. */
    public function test_an_archived_client_does_not_break_project_screens(): void
    {
        $project = $this->project('Arxiv müştərinin layihəsi', ['status' => ProjectStatus::Done->value]);

        $this->enterStudio($this->alfa);
        $this->actingAs($this->alfa->user('owner'));
        AccessMatrix::flushCache();

        // Müştərini silmək üçün onun bütün layihələri bitmiş/arxiv olmalıdır.
        $this->alfa->project->update(['status' => ProjectStatus::Archived->value]);
        $this->alfa->client->delete();

        // `Project::client()` `withTrashed()`-dir: ad itmir, ekran qırılmır.
        $fresh = $project->fresh();
        $this->assertNotNull($fresh->client, 'Silinmiş müştərinin adı layihədə qalmalıdır.');
        $this->assertSame($this->alfa->client->name, $fresh->client->name);

        $this->get(ProjectResource::getUrl('edit', ['record' => $project]))->assertOk();
        $this->get(ProjectResource::getUrl('index'))->assertOk();

        // Portal girişi isə bağlanmalıdır — sahibsiz hesab qalmasın.
        $this->assertSame(0, ClientUser::where('client_id', $this->alfa->client->id)->count());
    }

    // =====================================================================
    // 6. ALT-LAYİHƏLƏR
    // =====================================================================

    /**
     * Alt-layihə tam hüquqlu layihədir: öz mərhələləri və öz hazırlıq faizi var.
     * VALİDEYNİN faizi uşağı İÇİNƏ ALMIR — bu, qəsdən belədir (uşağın öz
     * kartı və öz çatı var), amma rəqəmlərin uyğunluğu yoxlanılmalıdır ki,
     * valideyn kartı uşağın işini saymadığı halda onu 100% elan etməsin.
     */
    public function test_a_subproject_keeps_its_own_readiness_independent_of_its_parent(): void
    {
        $parent = $this->project('Valideyn');
        $parentStage = $this->stage($parent, 'Eskiz', weight: 1, position: 1);
        $this->task($parentStage, 'Valideynin işi', TaskStatus::Done);

        $child = $this->project('Təmir', ['parent_project_id' => $parent->id]);
        $childStage = $this->stage($child, 'Söküntü', weight: 1, position: 1);
        $this->task($childStage, 'Uşağın işi');

        $this->assertSame(100, (int) $parent->fresh()->readiness, 'Valideyn öz mərhələsi üzrə 100%-dir.');
        $this->assertSame(0, (int) $child->fresh()->readiness, 'Alt-layihə müstəqil hesablanır.');
        $this->assertTrue($child->isSubproject());
        $this->assertFalse($parent->fresh()->isSubproject());

        // Uşağın tapşırığı valideynin mərhələsinə düşmür — çarpaz sızma yoxdur.
        $this->assertSame(1, $parentStage->tasks()->count());

        // Maliyyə də ikiqat sayılmır: hər layihənin büdcəsi özünündür.
        $parent->update(['budget_plan' => 1000]);
        $child->update(['budget_plan' => 400]);
        $this->assertSame('1000.00', $parent->fresh()->budget_plan);
        $this->assertSame('400.00', $child->fresh()->budget_plan);
    }

    /** Valideyn silinsə uşaq sahibsiz qalmır — müstəqil layihə kimi yaşayır. */
    public function test_a_subproject_survives_the_deletion_of_its_parent(): void
    {
        $parent = $this->project('Silinəcək valideyn');
        $child = $this->project('Qalan təmir', ['parent_project_id' => $parent->id]);

        $parent->delete();

        $fresh = $child->fresh();
        $this->assertNotNull($fresh, 'Alt-layihə valideynlə birlikdə yox olmamalıdır.');
        $this->assertNull($fresh->parent, 'Silinmiş valideyn münasibətdən çıxır.');
        $this->assertSame($parent->id, $fresh->parent_project_id, 'İstinad qalır ki, valideyn bərpa olunanda bağ qayıtsın.');
    }

    // =====================================================================
    // 7. SİLMƏ VƏ ARXİVLƏMƏ
    // =====================================================================

    /** Yaşayan layihəsi olan müştəri silinə bilməz — sahibsiz layihə qalmasın. */
    public function test_deleting_a_client_with_live_projects_is_blocked(): void
    {
        $this->enterStudio($this->alfa);

        try {
            $this->alfa->client->delete();
            $this->fail('Yaşayan layihəsi olan müştəri silinməməli idi.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('tamamlanmamış layihə', $e->getMessage());
        }

        $this->assertNotNull(Client::find($this->alfa->client->id));
        $this->assertNotNull(Project::find($this->alfa->project->id), 'Layihə sahibsiz qalmadı.');
    }

    /** Mərhələ silinəndə tapşırıqları da gedir və hazırlıq faizi yenilənir. */
    public function test_deleting_a_stage_removes_its_tasks_and_recalculates_readiness(): void
    {
        $project = $this->project('Mərhələ silmə');
        $keep = $this->stage($project, 'Qalan', weight: 1, position: 1);
        $drop = $this->stage($project, 'Silinən', weight: 1, position: 2);

        $this->task($keep, 'Qalan iş', TaskStatus::Done);
        $dropped = $this->task($drop, 'Silinən iş');

        $this->assertSame(50, (int) $project->fresh()->readiness, '(100+0)/2 = 50');

        $drop->delete();

        $this->assertNull(Task::find($dropped->id), 'Mərhələnin tapşırıqları da silinir (FK cascade).');
        $this->assertSame(100, (int) $project->fresh()->readiness, 'Faiz dərhal yenilənməlidir.');
    }

    /** Layihə silinəndə mərhələ/tapşırıq sətirləri bazada qalır (soft delete). */
    public function test_deleting_a_project_leaves_no_visible_orphans(): void
    {
        $project = $this->project('Silinən layihə');
        $stage = $this->stage($project, 'Eskiz', weight: 1, position: 1);
        $task = $this->task($stage, 'İş');

        $this->enterStudio($this->alfa);
        $this->actingAs($this->alfa->user('owner'));
        AccessMatrix::flushCache();

        $project->delete();

        // Sətirlər qalır — layihə bərpa oluna bilər...
        $this->assertNotNull(Task::find($task->id));
        $this->assertNotNull(Stage::find($stage->id));
        // ...amma heç bir iş ekranında görünmür.
        $this->assertNotContains($task->title, TaskResource::getEloquentQuery()->pluck('title')->all());
        $this->assertNotContains($task->title, $this->plannerTitles());
        $this->assertNotContains($task->title, $this->widgetTitles());

        // Bərpa: iş geri qayıdır, hazırlıq faizi qorunub.
        $project->restore();
        $this->assertContains($task->title, TaskResource::getEloquentQuery()->pluck('title')->all());
    }

    // =====================================================================
    // 8. OPTİMİSTİK KİLİD
    // =====================================================================

    /**
     * QA TAPINTI [YÜKSƏK] — DÜZƏLDİLMƏDİ (miqrasiya tələb edir, `app/Models`
     * mənim sahəmdə deyil): `projects` cədvəlində `row_version` sütunu YOXDUR və
     * `Project` `HasOptimisticLock` istifadə etmir. Optimistik kilid yalnız
     * maliyyə/razılaşdırma/versiyalama cədvəllərinə verilib
     * (`2026_09_13_010000_add_row_version_for_optimistic_lock.php`). Nəticə:
     * iki menecer eyni layihəni ardıcıl saxlayanda konflikt AŞKARLANMIR: eyni
     * sahəyə yazanda ikincisi birincinin dəyişikliyini səssizcə əzir. Zərərin
     * miqyasını yalnız Eloquent-in «dirty» izləməsi məhdudlaşdırır (B yalnız ÖZ
     * dəyişdiyi sütunları yazır, ona görə A-nın toxunduğu digər sahələr sağ
     * qalır) — bu, təsadüfi yumşaltmadır, kilid deyil: xəbərdarlıq yoxdur və
     * eyni sahə itir. Test mövcud davranışı SƏNƏDLƏŞDİRİR ki, `row_version`
     * sütunu əlavə edildiyi gün qırılsın və kilid həqiqətən yoxlanılsın.
     */
    public function test_project_edits_have_no_conflict_detection(): void
    {
        $project = $this->project('Kilid yoxlaması', ['budget_plan' => 1000]);

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('projects', 'row_version'),
            'Sütun əlavə olunubsa, Project modelinə HasOptimisticLock verilməli və bu test dəyişməlidir.'
        );

        $editorA = Project::find($project->id);
        $editorB = Project::find($project->id);

        $editorA->update(['budget_plan' => 2000, 'address' => 'A-nın ünvanı']);

        // B köhnə vəziyyətdən yazır — istisna ATILMIR (kilid olsaydı atılardı).
        $editorB->update(['budget_plan' => 3000]);

        $final = $project->fresh();
        $this->assertSame('3000.00', $final->budget_plan, 'Eyni sahə: A-nın yazısı səssizcə itdi.');
        // Yumşaltma: B `address`-ə toxunmadığı üçün o sütun yazılmadı.
        $this->assertSame('A-nın ünvanı', $final->address, 'Zərər yalnız ortaq sahə ilə məhdudlaşır.');
    }

    /** Maliyyə tərəfində isə kilid işləyir — müqayisə üçün etalon. */
    public function test_the_lock_does_work_where_it_was_installed(): void
    {
        $project = $this->project('Kilidli maliyyə');

        $line = $project->budgetLines()->create([
            'work_type' => 'Divar', 'unit' => 'm2', 'qty' => 10,
            'work_price' => 50, 'material_price' => 20, 'position' => 1,
        ]);

        $a = BudgetLine::find($line->id);
        $b = BudgetLine::find($line->id);

        $a->update(['work_price' => 60]);

        $this->expectException(RowVersionConflictException::class);
        $b->update(['work_price' => 70]);
    }
}
