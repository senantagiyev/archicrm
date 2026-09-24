<?php

namespace Tests\Feature\QA;

use App\Enums\ApprovalStatus;
use App\Enums\BriefStatus;
use App\Enums\ChangeRequestStatus;
use App\Enums\DecisionSource;
use App\Enums\FileVisibility;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Enums\PunchIssueStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Filament\Pages\Attention;
use App\Filament\Pages\ChatCenter;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\TaskPlanner;
use App\Filament\Resources\AutomationRuleResource\Pages\ListAutomationRules;
use App\Filament\Resources\TaskResource;
use App\Filament\Widgets\MyTasksWidget;
use App\Filament\Widgets\UpcomingDeadlinesWidget;
use App\Models\Approval;
use App\Models\AutomationRule;
use App\Models\Brief;
use App\Models\BudgetLine;
use App\Models\ChangeRequest;
use App\Models\Document;
use App\Models\Meeting;
use App\Models\Project;
use App\Models\ProjectDecision;
use App\Models\ProjectFile;
use App\Models\PunchListIssue;
use App\Models\Stage;
use App\Models\StageTemplate;
use App\Models\Task;
use App\Models\User;
use App\Services\Approvals\ApprovalService;
use App\Services\Automation\AutomationEngine;
use App\Services\Chat\ChatService;
use App\Services\Projects\ReadinessService;
use App\Services\Stages\StageTemplateService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\AutomationRuleSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\StageTemplateSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA — ADMIN MODULLARI, B DƏSTİ (layihə icrası nüvəsi).
 *
 * Hər test bir modulun MƏQSƏDİni yoxlayır. Tapılan problemlər `// QA TAPINTI:`
 * şərhi ilə işarələnib; test faktiki davranışı təsbit edir (yaşıl qalır) ki,
 * düzəliş ediləndə bu sətirlər dərhal qırmızıya düşsün.
 *
 * `QA TAPINTI … — DÜZƏLDİLDİ` işarəsi olan bloklarda problem məhsul kodunda
 * artıq həll edilib: test köhnə (baqlı) davranışı deyil, YENİ (düzgün) davranışı
 * qoruyur — və hər halda həm icazə verilən, həm rədd edilən tərəfi örtür ki,
 * düzəliş geri gedərsə yenə qırmızı olsun.
 */
class AdminModulesBQaTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $w;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        AccessMatrix::flushCache();

        $this->w = StudioWorld::make('alpha');

        // Bütün testlər studiyanın daxilində işləyir.
        app(TenantContext::class)->set($this->w->tenant->id);
        Filament::setTenant(null, true);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    private function asUser(string $role): User
    {
        $user = $this->w->user($role);
        $this->actingAs($user);
        AccessMatrix::flushCache();

        return $user;
    }

    private function task(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'project_id' => $this->w->project->id,
            'stage_id' => $this->w->stage->id,
            'title' => 'Tapşırıq',
            'status' => TaskStatus::Todo->value,
        ], $attributes));
    }

    // =====================================================================
    // 1. LAYİHƏ (Project) — MƏQSƏD: layihəni plandan təhvilə qədər aparmaq
    // =====================================================================

    /** MƏQSƏD: şablon seçiləndə mərhələ planı ardıcıl tarixlərlə açılmalıdır. */
    public function test_stage_template_creates_sequential_stages(): void
    {
        $this->seed(StageTemplateSeeder::class);
        $template = StageTemplate::where('key', 'design_project')->firstOrFail();

        $project = Project::create([
            'client_id' => $this->w->client->id,
            'name' => 'Şablon layihəsi',
            'type' => 'apartment',
            'status' => ProjectStatus::Active->value,
            'manager_user_id' => $this->w->user('project_manager')->id,
        ]);

        app(StageTemplateService::class)->apply($project, $template, Carbon::parse('2026-01-01'));

        $stages = $project->stages()->get();
        $this->assertSame($template->items->count(), $stages->count(), 'Şablonun hər bəndi bir mərhələ açmalıdır.');
        $this->assertSame('Briefinq', $stages->first()->name);
        $this->assertSame('2026-01-01', $stages->first()->date_plan_start->toDateString());
        $this->assertSame('2026-01-08', $stages->first()->date_plan_end->toDateString());
        // İkinci mərhələ birincinin bitməsindən BİR gün sonra başlayır.
        $this->assertSame('2026-01-09', $stages[1]->date_plan_start->toDateString());

        // QA TAPINTI [ORTA] — DÜZƏLDİLDİ: əvvəl şablonun ikinci tətbiqi mərhələ
        // planını ikiqatlayırdı (düymənin şərhi «bütün planı yenidən yazır»
        // deyirdi, servis isə yalnız əlavə edirdi). `StageTemplateService::apply()`
        // indi idempotentdir: adı planda olan bənd təkrar açılmır.
        // Ətraflı: tests/Feature/QA2/LifecycleTest.php.
        app(StageTemplateService::class)->apply($project, $template, Carbon::parse('2026-06-01'));
        $this->assertSame(
            $template->items->count(),
            $project->stages()->count(),
            'Şablonun ikinci tətbiqi mərhələləri təkrarlamamalıdır.'
        );
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: şablondan açılan mərhələlərin çəkisi artıq
     * həmişə 1 deyil. `StageTemplateService::apply()` indi
     * `weight = max(1, default_duration_days)` yazır: 60 günlük «Müəllif
     * nəzarəti» 3 günlük «Təhvil»dən 20 dəfə ağırdır, yəni hazırlıq faizi
     * mərhələ sayını yox, planlaşdırılmış iş həcmini əks etdirir. Müddəti
     * verilməyən bənd üçün çəki yenə 1-dir — hər iki tərəf aşağıda yoxlanılır.
     */
    public function test_stage_template_weights_stages_by_planned_duration(): void
    {
        $this->seed(StageTemplateSeeder::class);
        $template = StageTemplate::where('key', 'complex_project')->firstOrFail();

        $project = Project::create([
            'client_id' => $this->w->client->id,
            'name' => 'Çəki layihəsi',
            'type' => 'house',
            'status' => ProjectStatus::Active->value,
            'manager_user_id' => $this->w->user('project_manager')->id,
        ]);

        app(StageTemplateService::class)->apply($project, $template);

        $stages = $project->stages()->orderBy('position')->get();

        // Hər mərhələnin çəkisi şablon bəndinin plan müddətidir.
        $this->assertSame(
            $template->items->map(fn ($item) => max(1, (int) $item->default_duration_days))->all(),
            $stages->map(fn ($stage) => (int) $stage->weight)->all(),
            'Çəki plan müddətindən gəlməlidir.'
        );
        $this->assertGreaterThan(1, $stages->pluck('weight')->unique()->count(), 'Çəkilər artıq eyni deyil.');
        $this->assertSame(60, (int) $stages->firstWhere('name', 'Müəllif nəzarəti')->weight);
        $this->assertSame(3, (int) $stages->firstWhere('name', 'Təhvil')->weight);

        // Digər tərəf: müddəti göstərilməyən bənd çəkini 0-a salmır, 1 qalır.
        $undated = StageTemplate::create([
            'key' => 'undated_probe',
            'name' => ['az' => 'Müddətsiz şablon'],
            'position' => 99,
            'active' => true,
        ]);
        $undated->items()->create(['name' => ['az' => 'Müddətsiz mərhələ'], 'position' => 0, 'default_duration_days' => null]);

        $second = Project::create([
            'client_id' => $this->w->client->id,
            'name' => 'Müddətsiz layihə',
            'type' => 'house',
            'status' => ProjectStatus::Active->value,
            'manager_user_id' => $this->w->user('project_manager')->id,
        ]);

        app(StageTemplateService::class)->apply($second, $undated->fresh());

        $this->assertSame([1], $second->stages()->pluck('weight')->map(fn ($w) => (int) $w)->unique()->values()->all());
    }

    /** MƏQSƏD: hazırlıq faizi mərhələ ÇƏKİLƏRİNƏ görə hesablanmalıdır. */
    public function test_project_readiness_is_weighted_by_stage_weight(): void
    {
        $project = $this->w->project;
        $project->stages()->delete();

        $heavy = $project->stages()->create(['name' => 'Ağır', 'position' => 1, 'weight' => 3, 'status' => StageStatus::InProgress]);
        $light = $project->stages()->create(['name' => 'Yüngül', 'position' => 2, 'weight' => 1, 'status' => StageStatus::InProgress]);

        Task::create(['project_id' => $project->id, 'stage_id' => $heavy->id, 'title' => 'A', 'status' => TaskStatus::Done->value]);
        Task::create(['project_id' => $project->id, 'stage_id' => $light->id, 'title' => 'B', 'status' => TaskStatus::Todo->value]);

        $this->assertSame(100, $heavy->fresh()->readiness);
        $this->assertSame(0, $light->fresh()->readiness);
        // (100*3 + 0*1) / 4 = 75
        $this->assertSame(75, (int) $project->fresh()->readiness, 'Çəkili orta düzgün hesablanmalıdır.');
    }

    /** MƏQSƏD: çəkilərin cəmi 100 olmayanda da faiz düzgün normallaşmalıdır. */
    public function test_readiness_normalises_when_weights_do_not_sum_to_100(): void
    {
        $project = $this->w->project;
        $project->stages()->delete();

        $a = $project->stages()->create(['name' => 'A', 'position' => 1, 'weight' => 7, 'status' => StageStatus::Done]);
        $project->stages()->create(['name' => 'B', 'position' => 2, 'weight' => 13, 'status' => StageStatus::InProgress]);

        app(ReadinessService::class)->recalculateProject($project);

        // 7/20 = 35% — cəm 100 olmasa da nisbət saxlanılır.
        $this->assertSame(35, (int) $project->fresh()->readiness);
        $this->assertSame(StageStatus::Done, $a->fresh()->status);
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: «çəkisi 0 olan mərhələ hazırlığa heç vaxt
     * düşmür» iddiası artıq yanlışdır. BÜTÜN çəkilər 0 olanda ReadinessService
     * çəkiləri mənasız sayıb bərabər paya keçir — tam bitmiş layihə 100% verir.
     * Çəkilərdən heç olmasa biri müsbətdirsə köhnə qayda qalır: 0 çəkili mərhələ
     * hesaba pay vermir. Aşağıda hər iki hal yoxlanılır.
     */
    public function test_zero_weight_stages_fall_back_to_equal_share(): void
    {
        $project = $this->w->project;
        $project->stages()->delete();

        $project->stages()->create(['name' => 'Yeganə', 'position' => 1, 'weight' => 0, 'status' => StageStatus::Done]);

        app(ReadinessService::class)->recalculateProject($project);

        // Bütün çəkilər 0 → bərabər pay: tam bitmiş layihə 100% göstərir.
        $this->assertSame(100, (int) $project->fresh()->readiness, 'Sıfır çəkilər artıq faizi udmur.');

        // Müsbət çəkili mərhələ əlavə olunan kimi çəki yenidən mənalıdır və
        // 0 çəkili mərhələ hesaba heç nə vermir.
        $project->stages()->create(['name' => 'Ağır', 'position' => 2, 'weight' => 4, 'status' => StageStatus::InProgress]);

        app(ReadinessService::class)->recalculateProject($project);

        $this->assertSame(
            0,
            (int) $project->fresh()->readiness,
            'Çəkilər qarışıq olanda 0 çəkili bitmiş mərhələ pay almır — (100*0 + 0*4) / 4.'
        );
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: «Hazır» statuslu mərhələ artıq HƏR İKİ
     * ekranda 100%-dir — `recalculateStage()` də `status = Done` halında
     * tapşırıq payını deyil, 100 yazır.
     *
     * QA TAPINTI [KİÇİK] — DÜZƏLDİLDİ: `StageObserver::saved()` yalnız
     * `recalculateProject()` çağırırdı, ona görə mərhələnin ÖZ keşlənmiş sütunu
     * statusu dəyişən anda yenilənmirdi — növbəti tapşırıq hərəkətinə qədər
     * cədvəldə köhnə dəyər (50%) qalırdı. Observer artıq status dəyişəndə
     * `recalculateStage()` çağırır, yəni iki ekran DƏRHAL eyni faizi göstərir.
     */
    public function test_done_stage_shows_the_same_percentage_on_both_screens(): void
    {
        $project = $this->w->project;
        $project->stages()->delete();

        $stage = $project->stages()->create(['name' => 'Tək', 'position' => 1, 'weight' => 1, 'status' => StageStatus::InProgress]);
        Task::create(['project_id' => $project->id, 'stage_id' => $stage->id, 'title' => 'A', 'status' => TaskStatus::Done->value]);
        Task::create(['project_id' => $project->id, 'stage_id' => $stage->id, 'title' => 'B', 'status' => TaskStatus::Todo->value]);

        $stage->update(['status' => StageStatus::Done]);

        // Hər iki sütun status dəyişən anda yenilənir — əlavə hesablama lazım deyil.
        $this->assertSame(100, (int) $project->fresh()->readiness);
        $this->assertSame(100, $stage->fresh()->readiness, 'Bitmiş mərhələ sütunda da 100%-dir.');

        // Təkrar hesablama nəticəni dəyişmir (idempotent).
        app(ReadinessService::class)->recalculateStage($stage->fresh());

        $this->assertSame(100, $stage->fresh()->readiness);
        $this->assertSame(100, (int) $project->fresh()->readiness);
    }

    /** MƏQSƏD: mərhələ silinəndə onun tapşırıqları da getməlidir. */
    public function test_deleting_stage_cascades_its_tasks(): void
    {
        $stage = $this->w->stage;
        $taskId = $this->task(['stage_id' => $stage->id, 'title' => 'Silinəcək'])->id;

        $stage->delete();

        $this->assertDatabaseMissing('tasks', ['id' => $taskId]);
        $this->assertSame(0, (int) $this->w->project->fresh()->readiness);
    }

    /** MƏQSƏD: alt-layihə öz mərhələləri ilə müstəqil, amma valideynə bağlı olmalıdır. */
    public function test_subproject_is_independent_but_linked(): void
    {
        $sub = Project::create([
            'client_id' => $this->w->client->id,
            'parent_project_id' => $this->w->project->id,
            'name' => 'Təmir mərhələsi',
            'type' => 'apartment',
            'status' => ProjectStatus::Active->value,
            'manager_user_id' => $this->w->user('project_manager')->id,
        ]);

        $this->assertTrue($sub->isSubproject());
        $this->assertSame($this->w->project->id, $sub->parent->id);
        $this->assertTrue($this->w->project->subprojects->contains($sub));

        $subStage = $sub->stages()->create(['name' => 'Söküntü', 'position' => 1, 'weight' => 1, 'status' => StageStatus::Done]);
        app(ReadinessService::class)->recalculateProject($sub);

        // Alt-layihənin hazırlığı valideynə ÖTÜRÜLMÜR (dizayn qərarı olsa da,
        // valideyn 100% bitmiş alt-layihəni görmür).
        $this->assertSame(100, (int) $sub->fresh()->readiness);
        $this->assertSame(0, (int) $this->w->project->fresh()->readiness);
        $this->assertSame(StageStatus::Done, $subStage->fresh()->status);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: layihə soft-delete olunanda mərhələ və
     * tapşırıqlar hələ də bazada qalır (cascade yoxdur), AMMA `Attention::scoped()`
     * artıq `whereHas('project')` ilə süzür — ölü layihənin tapşırığı «Gecikmiş
     * tapşırıqlar» blokunda yaşamır. Layihə diri ikən eyni sətir görünür.
     */
    public function test_soft_deleted_project_tasks_drop_out_of_attention(): void
    {
        $task = $this->task(['deadline' => today()->subDays(5), 'title' => 'Ölü layihənin tapşırığı']);

        $this->asUser('owner');

        $before = collect(app(Attention::class)->blocks())->keyBy('key');
        $this->assertSame(1, $before['tasks']['count'], 'Layihə diri ikən tapşırıq blokdadır.');
        $this->assertSame('Ölü layihənin tapşırığı', $before['tasks']['items'][0]['title']);

        $this->w->project->delete(); // SoftDeletes

        $this->assertSoftDeleted('projects', ['id' => $this->w->project->id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertNull($task->fresh()->project, 'Sətir bazada diridir — yalnız ekranlardan süzülür.');

        $after = collect(app(Attention::class)->blocks())->keyBy('key');

        $this->assertSame(0, $after['tasks']['count'], 'Silinmiş layihənin tapşırığı artıq «Gecikmiş tapşırıqlar»da deyil.');
        $this->assertSame([], $after['tasks']['items'], 'Layihə adı yerinə «—» yazan sətir də qalmır.');
        $this->assertSame(0, $after['stages']['count'], 'Eyni qayda mərhələlərə də şamil olunur.');
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: Attention blokları artıq layihə statusunu
     * nəzərə alır — arxiv statuslu layihənin gecikmiş tapşırığı «bu gün nəyə
     * diqqət lazımdır» siyahısından çıxır. Layihə aktiv olduqca sətir görünür.
     */
    public function test_archived_project_tasks_drop_out_of_attention(): void
    {
        $this->task(['deadline' => today()->subDays(3), 'title' => 'Arxiv tapşırığı']);

        $this->asUser('owner');

        $before = collect(app(Attention::class)->blocks())->keyBy('key');
        $this->assertSame(1, $before['tasks']['count'], 'Aktiv layihədə tapşırıq diqqət siyahısındadır.');

        $this->w->project->update(['status' => ProjectStatus::Archived->value]);

        $after = collect(app(Attention::class)->blocks())->keyBy('key');

        $this->assertSame(0, $after['tasks']['count'], 'Arxivlənmiş layihənin işi diqqət siyahısına düşmür.');
        $this->assertSame(0, $after['approvals']['count'], 'Arxivin gözləyən razılaşdırması da çıxır.');
    }

    // =====================================================================
    // 2. TAPŞIRIQ (Task) — MƏQSƏD: kimin nəyi nə vaxta görəcəyini idarə etmək
    // =====================================================================

    /** MƏQSƏD: status axını todo → in_progress → done, tamamlanma vaxtı yazılır. */
    public function test_task_status_flow_stamps_and_clears_completed_at(): void
    {
        $task = $this->task(['assignee_user_id' => $this->w->user('designer')->id]);

        $this->assertNull($task->completed_at);

        $task->update(['status' => TaskStatus::InProgress]);
        $this->assertNull($task->fresh()->completed_at);

        $task->update(['status' => TaskStatus::Done]);
        $this->assertNotNull($task->fresh()->completed_at, 'Bitirilən tapşırıqda tamamlanma vaxtı olmalıdır.');

        // Geri qaytarılanda damğa təmizlənir.
        $task->update(['status' => TaskStatus::Todo]);
        $this->assertNull($task->fresh()->completed_at);
    }

    /** MƏQSƏD: gecikmə düzgün hesablanmalıdır — bugünkü son tarix gecikmə deyil. */
    public function test_task_overdue_calculation(): void
    {
        $this->assertTrue($this->task(['deadline' => today()->subDay()])->isOverdue());
        $this->assertFalse($this->task(['deadline' => today()])->isOverdue(), 'Bugün bitən tapşırıq gecikmiş sayılmır.');
        $this->assertFalse($this->task(['deadline' => today()->addDay()])->isOverdue());
        $this->assertFalse($this->task(['deadline' => null])->isOverdue());
        $this->assertFalse($this->task(['deadline' => today()->subYear(), 'status' => TaskStatus::Done->value])->isOverdue());
        $this->assertFalse($this->task(['deadline' => today()->subYear(), 'status' => TaskStatus::Cancelled->value])->isOverdue());
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: TaskResource::getEloquentQuery() artıq
     * `assignee_user_id = me` filtri qoymur; görünürlük LAYİHƏ ÜZVLÜYÜnə görə
     * kəsilir (`TaskResource::scopeToVisibleProjects()`). Layihə meneceri öz
     * layihəsinin BÜTÜN tapşırıqlarını görür — matrisdəki Mərhələ/Tapşırıq = Tam
     * ilə uyğun. Rədd edilən tərəf də yoxlanılır: üzvü olmadığı layihənin
     * tapşırığı, hətta ona təyin edilsə belə, görünmür.
     */
    public function test_project_manager_sees_every_task_of_own_project(): void
    {
        $pm = $this->w->user('project_manager');
        $designerTask = $this->task(['assignee_user_id' => $this->w->user('designer')->id, 'title' => 'Dizaynerin işi']);
        $ownTask = $this->task(['assignee_user_id' => $pm->id, 'title' => 'Menecerin işi']);

        $otherStage = $this->w->otherProject->stages()->create([
            'name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => StageStatus::InProgress,
        ]);
        Task::create([
            'project_id' => $this->w->otherProject->id,
            'stage_id' => $otherStage->id,
            'title' => 'Yad layihənin işi',
            'status' => TaskStatus::Todo->value,
            'assignee_user_id' => $pm->id,
        ]);

        $this->asUser('project_manager');

        $visible = TaskResource::getEloquentQuery()->pluck('title')->all();

        $this->assertContains('Menecerin işi', $visible);
        $this->assertContains(
            'Dizaynerin işi',
            $visible,
            'Layihə meneceri öz layihəsinin başqasına verilmiş tapşırığını da görür.'
        );
        $this->assertNotContains(
            'Yad layihənin işi',
            $visible,
            'Üzvü olmadığı layihə icraçı təyinatı ilə də açılmır — üzvlük təyinatı əvəz edir.'
        );
        $this->assertTrue($this->w->project->hasMember($pm), 'Menecer layihənin üzvüdür.');
        $this->assertFalse($this->w->otherProject->hasMember($pm));
        $this->assertNotNull($designerTask->fresh());
        $this->assertNotNull($ownTask->fresh());
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: filtrin əksi də bağlandı — başqa layihənin
     * tapşırığı icraçı olduğuna görə ARTIQ görünmür, çünki işçi həmin layihənin
     * üzvü deyil. Üzvü olduğu layihənin tapşırığı isə yerindədir.
     */
    public function test_designer_does_not_see_task_in_a_project_they_are_not_member_of(): void
    {
        $designer = $this->w->user('designer');
        $otherStage = $this->w->otherProject->stages()->create([
            'name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => StageStatus::InProgress,
        ]);

        Task::create([
            'project_id' => $this->w->otherProject->id,
            'stage_id' => $otherStage->id,
            'title' => 'Yad layihənin tapşırığı',
            'status' => TaskStatus::Todo->value,
            'assignee_user_id' => $designer->id,
        ]);

        $this->asUser('designer');

        $visible = TaskResource::getEloquentQuery()->pluck('title')->all();

        $this->assertFalse($this->w->otherProject->hasMember($designer), 'Dizayner bu layihənin üzvü deyil.');
        $this->assertNotContains(
            'Yad layihənin tapşırığı',
            $visible,
            'Üzvü olmadığı layihənin tapşırığı artıq görünmür — görünürlük layihəyə görə kəsilir.'
        );
        $this->assertContains(
            'Planlaşdırma',
            $visible,
            'Üzvü olduğu layihənin tapşırığı isə açıq qalır.'
        );
    }

    /**
     * MƏQSƏD: MyTasksWidget «mənim açıq işim» sualına cavab verməlidir.
     *
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ (yan təsir): vidjet indi son tarixi
     * OLMAYAN tapşırıqları da gətirir, ona görə StudioWorld-ün tarixsiz
     * «Planlaşdırma» işi də siyahıdadır — gözlənilən say 1 deyil, 2-dir.
     */
    public function test_my_tasks_widget_shows_my_open_tasks_this_week_and_undated(): void
    {
        $designer = $this->w->user('designer');

        $mine = $this->task(['assignee_user_id' => $designer->id, 'deadline' => today(), 'title' => 'Mənim']);
        $others = $this->task(['assignee_user_id' => $this->w->user('visualizer')->id, 'deadline' => today(), 'title' => 'Başqasının']);
        $done = $this->task(['assignee_user_id' => $designer->id, 'deadline' => today(), 'status' => TaskStatus::Done->value, 'title' => 'Bitmiş']);
        $future = $this->task(['assignee_user_id' => $designer->id, 'deadline' => today()->addWeeks(3), 'title' => 'Gələcək']);

        $this->asUser('designer');

        Livewire::test(MyTasksWidget::class)
            // StudioWorld-ün tarixsiz «Planlaşdırma» işi də dizaynerindir.
            ->assertCanSeeTableRecords([$mine, $this->w->task])
            ->assertCanNotSeeTableRecords([$others, $done, $future])
            ->assertCountTableRecords(2);
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: son tarixi olmayan tapşırıq artıq
     * MyTasksWidget-də GÖRÜNÜR (`deadline IS NULL OR deadline <= həftənin sonu`),
     * yəni TaskPlanner-in və ApprovalService::createRevisionTask()-in yaratdığı
     * tarixsiz iş daha itmir. Sıralama saxlanılır: tarixi olanlar öncə, tarixsizlər
     * siyahının sonunda.
     */
    public function test_task_without_deadline_is_visible_in_my_tasks_widget(): void
    {
        $designer = $this->w->user('designer');
        $dated = $this->task(['assignee_user_id' => $designer->id, 'deadline' => today(), 'title' => 'Tarixli']);
        $noDeadline = $this->task(['assignee_user_id' => $designer->id, 'deadline' => null, 'title' => 'Tarixsiz']);

        $this->asUser('designer');

        $component = Livewire::test(MyTasksWidget::class)
            // StudioWorld-ün «Planlaşdırma» işi də tarixsizdir.
            ->assertCanSeeTableRecords([$dated, $noDeadline, $this->w->task])
            ->assertCountTableRecords(3);

        // `paginated()` səbəbindən nəticə paginator-dur — sətirləri `items()` verir.
        $titles = collect($component->instance()->getTableRecords()->items())->pluck('title')->all();

        $this->assertSame('Tarixli', $titles[0], 'Son tarixi olan iş öncə sıralanır.');
        $this->assertContains('Tarixsiz', array_slice($titles, 1), 'Tarixsizlər siyahının sonundadır.');
    }

    /** MƏQSƏD: TaskPlanner işi status sütunlarına düzgün yığmalıdır. */
    public function test_task_planner_groups_by_status_and_drops_cancelled(): void
    {
        $this->task(['assignee_user_id' => $this->w->user('designer')->id, 'status' => TaskStatus::Todo->value, 'title' => 'T1']);
        $this->task(['assignee_user_id' => $this->w->user('designer')->id, 'status' => TaskStatus::InProgress->value, 'title' => 'T2']);
        $this->task(['assignee_user_id' => $this->w->user('designer')->id, 'status' => TaskStatus::Done->value, 'title' => 'T3']);
        $this->task(['assignee_user_id' => $this->w->user('designer')->id, 'status' => TaskStatus::Cancelled->value, 'title' => 'T4']);

        $this->asUser('owner');

        $planner = Livewire::test(TaskPlanner::class)->instance();
        $groups = $planner->getGroupedTasks();

        // StudioWorld-ün öz «Planlaşdırma» tapşırığı da `todo`-dadır.
        $this->assertSame(2, $groups[TaskStatus::Todo->value]->count());
        $this->assertSame(1, $groups[TaskStatus::InProgress->value]->count());
        $this->assertSame(1, $groups[TaskStatus::Done->value]->count());
        $this->assertArrayNotHasKey(TaskStatus::Cancelled->value, $groups, 'Ləğv edilmiş sütun yoxdur.');
        // StudioWorld-ün öz tapşırığı da daxil: ümumi say 5.
        $this->assertSame(4, $planner->getTotalCount());
    }

    /**
     * QA TAPINTI [ORTA]: TaskPlanner «plan» ekranıdır, amma planlaşdırma
     * hərəkəti yoxdur — sütunlar arası sürüşdürmə/status dəyişmə metodu
     * səhifədə mövcud deyil. Ekran yalnız oxunur.
     */
    public function test_task_planner_has_no_move_action(): void
    {
        $methods = get_class_methods(TaskPlanner::class);

        foreach (['moveTask', 'updateStatus', 'setStatus', 'move', 'reorder', 'drop'] as $expected) {
            $this->assertNotContains($expected, $methods, "TaskPlanner::{$expected}() gözlənilmirdi.");
        }
    }

    /** MƏQSƏD: TaskPlanner-in «mine» filtri yalnız mənim işimi qoymalıdır. */
    public function test_task_planner_mine_scope(): void
    {
        $owner = $this->w->user('owner');
        $this->task(['assignee_user_id' => $owner->id, 'status' => TaskStatus::Todo->value, 'title' => 'Sahibin işi']);

        $this->asUser('owner');

        $component = Livewire::test(TaskPlanner::class);
        $this->assertSame(2, $component->instance()->getTotalCount());

        $component->set('scope', 'mine');
        $this->assertSame(1, $component->instance()->getTotalCount());
    }

    // =====================================================================
    // 3. MƏRHƏLƏLƏR — MƏQSƏD: layihənin vaxt planını idarə etmək
    // =====================================================================

    /** MƏQSƏD: gecikən mərhələ avtomatik «Gecikib» statusuna keçməlidir. */
    public function test_overdue_stages_are_marked_automatically(): void
    {
        $late = $this->w->project->stages()->create([
            'name' => 'Gecikən', 'position' => 5, 'weight' => 1,
            'status' => StageStatus::InProgress, 'date_plan_end' => today()->subDays(2),
        ]);
        $onTime = $this->w->project->stages()->create([
            'name' => 'Vaxtında', 'position' => 6, 'weight' => 1,
            'status' => StageStatus::InProgress, 'date_plan_end' => today()->addDays(2),
        ]);
        $doneLate = $this->w->project->stages()->create([
            'name' => 'Bitmiş', 'position' => 7, 'weight' => 1,
            'status' => StageStatus::Done, 'date_plan_end' => today()->subDays(10),
        ]);

        $this->artisan('stages:mark-overdue')->assertSuccessful();

        $this->assertSame(StageStatus::Overdue, $late->fresh()->status);
        $this->assertSame(StageStatus::InProgress, $onTime->fresh()->status);
        $this->assertSame(StageStatus::Done, $doneLate->fresh()->status, 'Bitmiş mərhələ gecikmiş sayılmır.');
    }

    /** MƏQSƏD: silinmiş layihənin mərhələləri gecikmə siqnalı verməməlidir. */
    public function test_overdue_scan_skips_soft_deleted_project(): void
    {
        $stage = $this->w->project->stages()->create([
            'name' => 'Ölü', 'position' => 9, 'weight' => 1,
            'status' => StageStatus::InProgress, 'date_plan_end' => today()->subDays(30),
        ]);

        $this->w->project->delete();

        $this->artisan('stages:mark-overdue')->assertSuccessful();

        $this->assertSame(StageStatus::InProgress, $stage->fresh()->status);
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: UpcomingDeadlinesWidget artıq layihə
     * üzvlüyünə görə kəsilir (`scoped()`). Layihə meneceri ÜZVÜ OLMADIĞI
     * layihənin mərhələsini görmür, öz layihəsininkini isə görür — vidjeti
     * açmaq hüququ ilə sətri görmək hüququ ayrılıb.
     */
    public function test_upcoming_deadlines_widget_is_project_scoped(): void
    {
        $this->w->otherProject->stages()->create([
            'name' => 'Yad layihənin mərhələsi', 'position' => 1, 'weight' => 1,
            'status' => StageStatus::InProgress, 'date_plan_end' => today()->addDays(3),
        ]);
        $own = $this->w->project->stages()->create([
            'name' => 'Öz layihəmin mərhələsi', 'position' => 2, 'weight' => 1,
            'status' => StageStatus::InProgress, 'date_plan_end' => today()->addDays(3),
        ]);

        $pm = $this->asUser('project_manager');
        $this->assertFalse($this->w->otherProject->hasMember($pm));
        $this->assertTrue($this->w->project->hasMember($pm));

        Livewire::test(UpcomingDeadlinesWidget::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords(Stage::where('name', 'Yad layihənin mərhələsi')->get());
    }

    // =====================================================================
    // 4. RAZILAŞDIRMA (Approval) — MƏQSƏD: müştəri qərarını sənədləşdirmək
    // =====================================================================

    /** MƏQSƏD: təkrar göndərişdə versiya artmalı, köhnəsi qaralamaya düşməlidir. */
    public function test_approval_resubmission_increments_version_and_supersedes(): void
    {
        Notification::fake();

        // StudioWorld bu smeta sətri üçün artıq bir razılaşdırma qurub — təmiz başlayırıq.
        Approval::query()->delete();

        $service = app(ApprovalService::class);
        $line = BudgetLine::findOrFail($this->w->budgetLine->id);
        $pm = $this->w->user('project_manager');

        $first = $service->request($line, $pm);
        $this->assertSame(1, (int) $first->version);
        $this->assertSame(ApprovalStatus::Pending, $first->status);

        $service->decide($first, false, 'Qiymət yüksəkdir');
        $this->assertSame(ApprovalStatus::Rejected, $first->fresh()->status);

        $second = $service->request($line, $pm);
        $this->assertSame(2, (int) $second->version, 'Rədd edilmiş dövr də versiyaya sayılır.');

        // Üçüncüsü hələ cavabsız ikincini qaralamaya salır.
        $third = $service->request($line, $pm);
        $this->assertSame(3, (int) $third->version);
        $this->assertSame(ApprovalStatus::Draft, $second->fresh()->status);
        $this->assertSame(1, $third->history()->count() > 0 ? 1 : 0);
    }

    /** MƏQSƏD: cavab müddəti layihənin pəncərəsindən avtomatik hesablanmalıdır. */
    public function test_approval_respond_by_comes_from_project_window(): void
    {
        Notification::fake();

        $this->w->project->update(['client_response_days' => 5]);

        $line = BudgetLine::findOrFail($this->w->budgetLine->id);
        $approval = app(ApprovalService::class)->request($line, $this->w->user('project_manager'));

        $this->assertSame(today()->addDays(5)->toDateString(), $approval->respond_by->toDateString());
        $this->assertFalse($approval->isOverdue());

        $approval->update(['respond_by' => today()->subDay()]);
        $this->assertTrue($approval->fresh()->isOverdue());
    }

    /**
     * QA TAPINTI [KİÇİK]: ApprovalService::request() `$approvable->project`
     * əlaqəsini yeniləmir. Çağıran tərəfdə əlaqə artıq yüklənibsə (Filament
     * relation manager-ləri bunu müntəzəm edir) cavab müddəti KÖHNƏ
     * `client_response_days` dəyərindən hesablanır.
     */
    public function test_respond_by_uses_stale_project_relation_when_already_loaded(): void
    {
        Notification::fake();

        // `budgetLines()->create()` qayıdan obyektdə valideyn əlaqə artıq bağlıdır.
        $line = $this->w->project->budgetLines()->create([
            'work_type' => 'Tavan', 'unit' => 'm2', 'qty' => 1,
            'work_price' => 10, 'material_price' => 0, 'position' => 2,
            'visible_to_client' => true,
        ]);

        // Bazada 5-ə dəyişirik, amma yaddaşdakı obyekt hələ 3 saxlayır.
        Project::whereKey($this->w->project->id)->update(['client_response_days' => 5]);
        $this->assertSame(3, (int) $line->project->client_response_days, 'Yaddaşdakı əlaqə köhnədir.');

        $approval = app(ApprovalService::class)->request($line, $this->w->user('project_manager'));

        $this->assertSame(
            today()->addDays(3)->toDateString(),
            $approval->respond_by->toDateString(),
            'Cavab müddəti köhnə dəyərdən hesablandı (gözlənilən: +5 gün).'
        );
    }

    /** MƏQSƏD: variantlı razılaşdırmada müştəri birini seçməlidir. */
    public function test_variant_approval_requires_a_choice(): void
    {
        Notification::fake();

        $approval = app(ApprovalService::class)->request(
            $this->w->budgetLine,
            $this->w->user('project_manager'),
            null,
            [['key' => 'a', 'label' => 'Variant A'], ['key' => 'b', 'label' => 'Variant B']],
        );

        $this->assertTrue($approval->hasVariants());

        try {
            app(ApprovalService::class)->decide($approval, true, null, $this->w->portalUser, null);
            $this->fail('Variant seçilmədən təsdiq qəbul edilməməli idi.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('variantlardan biri', $e->getMessage());
        }

        try {
            app(ApprovalService::class)->decide($approval, true, null, $this->w->portalUser, 'c');
            $this->fail('Siyahıda olmayan variant qəbul edilməməli idi.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('variantlardan biri', $e->getMessage());
        }

        $decided = app(ApprovalService::class)->decide($approval, true, null, $this->w->portalUser, 'b');
        $this->assertSame('b', $decided->chosen_variant);
        $this->assertSame(ApprovalStatus::Approved, $decided->status);
    }

    /** MƏQSƏD: rədd şərhsiz qəbul edilməməli, rədd düzəliş tapşırığı yaratmalıdır. */
    public function test_rejection_requires_comment_and_creates_revision_task(): void
    {
        Notification::fake();
        $this->seed(AutomationRuleSeeder::class);
        app(AutomationEngine::class)->flush();

        $approval = app(ApprovalService::class)->request($this->w->budgetLine, $this->w->user('project_manager'));

        try {
            app(ApprovalService::class)->decide($approval, false, '   ');
            $this->fail('Şərhsiz rədd qəbul edilməməli idi.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('şərh məcburidir', $e->getMessage());
        }

        $before = Task::count();
        app(ApprovalService::class)->decide($approval, false, 'Rəng uyğun deyil');

        $this->assertSame($before + 1, Task::count());
        $revision = Task::latest('id')->first();
        $this->assertStringStartsWith('Düzəliş:', $revision->title);
        $this->assertSame($this->w->user('project_manager')->id, $revision->assignee_user_id);

        // QA TAPINTI [ORTA]: avtomatik yaradılan düzəliş tapşırığında SON TARİX
        // yoxdur — yuxarıdakı testə görə belə tapşırıq «Mənim tapşırıqlarım»
        // vidjetində heç vaxt görünmür, yəni siqnal itir.
        $this->assertNull($revision->deadline);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: ApprovalService::decide() artıq qərar
     * verilmiş razılaşdırmanı bloklayır (`InvalidArgumentException`), yəni
     * təsdiqlənmiş sətir rəddə çevrilə və ikinci düzəliş tapşırığı doğura
     * bilmir. İcazə verilən yol: dizayner `request()` ilə növbəti versiyanı
     * göndərir — həmin YENİ versiya normal qərar qəbul edir.
     */
    public function test_already_decided_approval_cannot_be_flipped(): void
    {
        Notification::fake();

        $approval = app(ApprovalService::class)->request($this->w->budgetLine, $this->w->user('project_manager'));

        app(ApprovalService::class)->decide($approval, true, null, $this->w->portalUser);
        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);

        $tasksBefore = Task::count();

        try {
            app(ApprovalService::class)->decide($approval->fresh(), false, 'Fikrimi dəyişdim', $this->w->portalUser);
            $this->fail('Qərar verilmiş razılaşdırma yenidən qərara açılmamalı idi.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('qərar artıq verilib', $e->getMessage());
        }

        $this->assertSame(
            ApprovalStatus::Approved,
            $approval->fresh()->status,
            'Status toxunulmaz qalır — versiyalaşma məntiqi qorunur.'
        );
        $this->assertSame($tasksBefore, Task::count(), 'Bloklanan qərar düzəliş tapşırığı da doğurmur.');

        // Yeni dövrə: növbəti versiya açılır və o, sərbəst qərar qəbul edir.
        $next = app(ApprovalService::class)->request($this->w->budgetLine->fresh(), $this->w->user('project_manager'));
        $this->assertGreaterThan((int) $approval->version, (int) $next->version, 'Yeni dövrə yeni versiyadır.');

        app(ApprovalService::class)->decide($next, false, 'Rəng uyğun deyil', $this->w->portalUser);
        $this->assertSame(ApprovalStatus::Rejected, $next->fresh()->status);
    }

    /** MƏQSƏD: müştəriyə gizli smeta sətri razılaşdırmaya getməməlidir. */
    public function test_internal_budget_line_cannot_be_sent_for_approval(): void
    {
        Notification::fake();

        $this->w->budgetLine->update(['visible_to_client' => false]);

        $this->expectException(\InvalidArgumentException::class);
        app(ApprovalService::class)->request($this->w->budgetLine->fresh(), $this->w->user('project_manager'));
    }

    // =====================================================================
    // 5. AVTOMATLAŞDIRMA (AutomationRule)
    // =====================================================================

    /** MƏQSƏD: qlobal açar bağlananda heç bir avtomatlaşdırma işləməməlidir. */
    public function test_global_switch_stops_every_rule(): void
    {
        $this->seed(AutomationRuleSeeder::class);

        $engine = app(AutomationEngine::class);
        $engine->flush();
        $this->assertTrue($engine->isEnabled('rule-18'));

        config(['automations.enabled' => false]);
        $engine->flush();

        $this->assertFalse($engine->isEnabled('rule-18'));
        $this->assertFalse($engine->isEnabled('rule-16'));
    }

    /** MƏQSƏD: deaktiv qayda effekt verməməlidir. */
    public function test_disabled_rule_does_not_run(): void
    {
        Notification::fake();
        $this->seed(AutomationRuleSeeder::class);

        AutomationRule::where('code', 'rule-18')->update(['enabled' => false]);
        app(AutomationEngine::class)->flush();

        $approval = app(ApprovalService::class)->request($this->w->budgetLine, $this->w->user('project_manager'));
        $before = Task::count();

        app(ApprovalService::class)->decide($approval, false, 'Rədd');

        $this->assertSame($before, Task::count(), 'Deaktiv qayda düzəliş tapşırığı yaratmamalıdır.');
    }

    /** MƏQSƏD: səhv konfiqurasiya (naməlum kod) xəta atmamalı, səssiz keçməlidir. */
    public function test_unknown_rule_code_is_silently_off(): void
    {
        $this->seed(AutomationRuleSeeder::class);
        app(AutomationEngine::class)->flush();

        $this->assertFalse(app(AutomationEngine::class)->isEnabled('rule-does-not-exist'));

        $ran = app(AutomationEngine::class)->once('rule-does-not-exist', 'key-1', fn () => null);
        $this->assertTrue($ran, 'once() qaydanın mövcudluğunu yoxlamır — yalnız dedup edir.');
    }

    /** MƏQSƏD: eyni tetikləyici iki dəfə işə düşməməlidir (dövrə/təkrar riski). */
    public function test_once_guard_prevents_repeat_fire(): void
    {
        $engine = app(AutomationEngine::class);
        $calls = 0;

        $this->assertTrue($engine->once('rule-16', 'approval:1:today', function () use (&$calls) {
            $calls++;
        }));
        $this->assertFalse($engine->once('rule-16', 'approval:1:today', function () use (&$calls) {
            $calls++;
        }));

        $this->assertSame(1, $calls);
    }

    /** MƏQSƏD: effekt xəta atarsa açar buraxılmalı ki, növbəti tick təkrarlasın. */
    public function test_failed_effect_releases_the_dedup_key(): void
    {
        $engine = app(AutomationEngine::class);

        try {
            $engine->once('rule-17', 'k', fn () => throw new \RuntimeException('boom'));
            $this->fail('İstisna gözlənilirdi.');
        } catch (\RuntimeException) {
            // gözlənilir
        }

        $this->assertDatabaseMissing('automation_runs', ['rule_code' => 'rule-17', 'dedup_key' => 'k']);
        $this->assertTrue($engine->once('rule-17', 'k', fn () => null));
    }

    /**
     * MƏQSƏD: rədd → düzəliş tapşırığı avtomatikası öz tetikləyicisini yenidən
     * yandırmamalıdır (dövrə riski).
     */
    public function test_revision_task_automation_does_not_loop(): void
    {
        Notification::fake();
        $this->seed(AutomationRuleSeeder::class);
        app(AutomationEngine::class)->flush();

        $approvalsBefore = Approval::count();
        $approval = app(ApprovalService::class)->request($this->w->budgetLine, $this->w->user('project_manager'));
        $before = Task::count();

        app(ApprovalService::class)->decide($approval, false, 'Rədd');

        // Yaradılan tapşırıq yeni razılaşdırma doğurmur — dövrə yoxdur.
        $this->assertSame($before + 1, Task::count());
        $this->assertSame($approvalsBefore + 1, Approval::count(), 'Düzəliş tapşırığı yeni razılaşdırma tetikləmir.');
    }

    /**
     * QA TAPINTI [BLOKER] — DÜZƏLDİLDİ: studiya üçün «copy-on-write» override
     * düzgün yaranır.
     *
     * `tenant_id` artıq modelin `$fillable` siyahısındadır (yəni
     * `updateOrCreate()` onu sükutla atmır), AutomationRuleResource-un
     * ToggleColumn-u isə override sətrini `forceFill()` ilə yazır. Nəticə:
     * studiyanın «söndür» qərarı ÖZ sətrini yaradır, platformanın
     * (`tenant_id = null`) sətri toxunulmaz qalır.
     */
    public function test_automation_rule_tenant_override_keeps_tenant_id(): void
    {
        $this->seed(AutomationRuleSeeder::class);

        $this->assertContains('tenant_id', (new AutomationRule)->getFillable());

        $created = AutomationRule::updateOrCreate(
            ['tenant_id' => $this->w->tenant->id, 'code' => 'rule-18-override-probe'],
            ['name' => 'Test', 'trigger' => 'test', 'priority' => 'medium', 'enabled' => false],
        );

        $this->assertSame(
            $this->w->tenant->id,
            $created->tenant_id,
            'tenant_id fillable-dır — studiya override-ı öz studiyasına yazılır.'
        );

        // Admin paneldəki açarın özü: platforma qaydası söndürüləndə override yaranır.
        $platformRule = AutomationRule::query()->whereNull('tenant_id')->where('code', 'rule-18')->firstOrFail();
        $this->assertTrue((bool) $platformRule->enabled);

        $this->asUser('owner');

        Livewire::test(ListAutomationRules::class)
            ->call('updateTableColumnState', 'enabled', (string) $platformRule->getKey(), false);

        $this->assertTrue(
            (bool) $platformRule->fresh()->enabled,
            'Platforma sətri toxunulmur — digər studiyaların bildirişləri sönmür.'
        );

        $override = AutomationRule::query()
            ->where('tenant_id', $this->w->tenant->id)
            ->where('code', 'rule-18')
            ->first();

        $this->assertNotNull($override, 'Studiya üçün ayrıca override sətri yaranmalıdır.');
        $this->assertFalse((bool) $override->enabled);

        // Mühərrik override-i görür: studiyada qayda sönür.
        app(AutomationEngine::class)->flush();
        $this->assertFalse(app(AutomationEngine::class)->isEnabled('rule-18'));
    }

    // =====================================================================
    // 6. ATTENTION — MƏQSƏD: «bu gün nəyə əl atmalıyam» sualına cavab
    // =====================================================================

    /** MƏQSƏD: hər mənbə öz blokuna DÜZGÜN qeydləri gətirməlidir. */
    public function test_attention_blocks_pick_up_the_right_records(): void
    {
        Notification::fake();

        // Gecikmiş tapşırıq.
        $this->task(['deadline' => today()->subDays(4), 'title' => 'Gecikmiş']);
        // Vaxtında tapşırıq — düşməməlidir.
        $this->task(['deadline' => today()->addDays(4), 'title' => 'Vaxtında']);
        // Gecikmiş mərhələ.
        $this->w->project->stages()->create([
            'name' => 'Gecikmiş mərhələ', 'position' => 3, 'weight' => 1,
            'status' => StageStatus::InProgress, 'date_plan_end' => today()->subDay(),
        ]);
        // Gecikmiş ödəniş — statusu hələ `pending`, tarixi keçib.
        $this->w->project->payments()->create([
            'title' => 'Gecikmiş ödəniş', 'amount' => 100,
            'status' => PaymentStatus::Pending->value, 'due_date' => today()->subDays(3),
        ]);
        // Gələcək ödəniş — düşməməlidir (StudioWorld-dən gələn «Avans»).

        $this->asUser('owner');
        $blocks = collect(app(Attention::class)->blocks())->keyBy('key');

        $this->assertSame(1, $blocks['tasks']['count'], 'Yalnız gecikmiş tapşırıq.');
        $this->assertSame('Gecikmiş', $blocks['tasks']['items'][0]['title']);

        $this->assertSame(1, $blocks['stages']['count']);
        $this->assertSame('Gecikmiş mərhələ', $blocks['stages']['items'][0]['title']);

        $this->assertSame(1, $blocks['payments']['count'], 'Gələcək ödəniş diqqət siyahısına düşməməlidir.');

        $this->assertSame(1, $blocks['approvals']['count'], 'StudioWorld-ün gözləyən razılaşdırması.');
        $this->assertSame(0, $blocks['briefs']['count']);
        $this->assertSame(0, $blocks['risks']['count']);
    }

    /** MƏQSƏD: artıq «Gecikib» işarələnmiş ödəniş ikiqat sayılmamalıdır. */
    public function test_attention_payments_do_not_double_count(): void
    {
        $this->w->project->payments()->create([
            'title' => 'İşarələnmiş', 'amount' => 50,
            'status' => PaymentStatus::Overdue->value, 'due_date' => today()->subDays(9),
        ]);

        $this->asUser('owner');
        $blocks = collect(app(Attention::class)->blocks())->keyBy('key');

        $this->assertSame(1, $blocks['payments']['count']);
    }

    /**
     * QA TAPINTI [CİDDİ] — DÜZƏLDİLDİ: Attention və TaskResource artıq EYNİ
     * qaydadan (layihə üzvlüyü) istifadə edir, ona görə menecerin Attention-da
     * gördüyü tapşırığın linki açılır — «Record not found» yoxdur. Üzvü olmadığı
     * layihənin işi isə hər iki ekranda eyni cür gizlidir.
     */
    public function test_attention_links_to_a_task_the_manager_can_open(): void
    {
        $task = $this->task(['assignee_user_id' => $this->w->user('designer')->id, 'deadline' => today()->subDay(), 'title' => 'Dizaynerin gecikmiş işi']);

        $otherStage = $this->w->otherProject->stages()->create([
            'name' => 'Yad mərhələ', 'position' => 1, 'weight' => 1, 'status' => StageStatus::InProgress,
        ]);
        $foreign = Task::create([
            'project_id' => $this->w->otherProject->id,
            'stage_id' => $otherStage->id,
            'title' => 'Yad layihənin gecikmiş işi',
            'status' => TaskStatus::Todo->value,
            'deadline' => today()->subDay(),
            'assignee_user_id' => $this->w->user('owner')->id,
        ]);

        $this->asUser('project_manager');

        $blocks = collect(app(Attention::class)->blocks())->keyBy('key');
        $this->assertSame(1, $blocks['tasks']['count'], 'Attention menecerə yalnız öz layihəsinin işini göstərir…');
        $this->assertSame('Dizaynerin gecikmiş işi', $blocks['tasks']['items'][0]['title']);

        // …və resursun sorğusu həmin sətri qaytarır → link işləyir.
        $this->assertNotNull(
            TaskResource::getEloquentQuery()->find($task->id),
            'TaskResource eyni sətri tapır: link qırıq deyil.'
        );

        // Əks tərəf: yad layihənin işi nə Attention-da, nə də resursda var.
        $this->assertNotContains('Yad layihənin gecikmiş işi', collect($blocks['tasks']['items'])->pluck('title')->all());
        $this->assertNull(TaskResource::getEloquentQuery()->find($foreign->id));
    }

    /** MƏQSƏD: cavabsız brif «diqqət» siyahısına düşməli, qaralama düşməməlidir. */
    public function test_attention_brief_block_only_lists_waiting_briefs(): void
    {
        Brief::create([
            'project_id' => $this->w->project->id,
            'status' => BriefStatus::Submitted->value,
            'submitted_at' => now()->subDays(2),
        ]);
        Brief::create([
            'project_id' => $this->w->otherProject->id,
            'status' => BriefStatus::Draft->value,
        ]);

        $this->asUser('owner');
        $blocks = collect(app(Attention::class)->blocks())->keyBy('key');

        $this->assertSame(1, $blocks['briefs']['count'], 'Yalnız cavab gözləyən brif sayılmalıdır.');
        $this->assertStringContainsString('növbə dizaynerdədir', $blocks['briefs']['items'][0]['meta']);
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: İdarəetmə Paneli (Dashboard) artıq layihə
     * üzvlüyünə görə kəsilir — gecikmə sayğacı, «bugünkü tapşırıqlar» və «son
     * layihələr» dizaynerə yad layihəni göstərmir. Öz layihəsinin gecikmiş işi
     * isə sayılır və panel ilə TaskResource eyni cavabı verir. Sahibkar üçün
     * məhdudiyyət yoxdur — o hər iki sətri görür.
     */
    public function test_dashboard_task_tiles_are_scoped_to_the_user(): void
    {
        $otherStage = $this->w->otherProject->stages()->create([
            'name' => 'Yad', 'position' => 1, 'weight' => 1, 'status' => StageStatus::InProgress,
        ]);
        Task::create([
            'project_id' => $this->w->otherProject->id,
            'stage_id' => $otherStage->id,
            'title' => 'Yad layihənin gecikmiş işi',
            'status' => TaskStatus::Todo->value,
            'deadline' => today()->subDays(2),
            'assignee_user_id' => $this->w->user('owner')->id,
        ]);
        $this->task(['deadline' => today()->subDay(), 'title' => 'Öz layihəmin gecikmiş işi']);

        $designer = $this->asUser('designer');
        $this->assertFalse($this->w->otherProject->hasMember($designer));

        $dashboard = new Dashboard;

        $overdueTile = collect($dashboard->stats())->firstWhere('label', 'Gecikmiş tapşırıqlar');
        $this->assertSame('1', $overdueTile['value'], 'Sayğac yalnız öz layihəsinin gecikmiş tapşırığını sayır.');

        $todayTitles = $dashboard->todayTasks()->pluck('title')->all();
        $this->assertContains('Öz layihəmin gecikmiş işi', $todayTitles);
        $this->assertNotContains(
            'Yad layihənin gecikmiş işi',
            $todayTitles,
            'Dashboard yad layihənin tapşırıq BAŞLIĞINI artıq göstərmir.'
        );
        $this->assertNotContains(
            $this->w->otherProject->name,
            $dashboard->recentProjects()->pluck('name')->all(),
            '«Son layihələr» siyahısı da eyni filtrlə kəsilir.'
        );

        // İki ekran indi eyni sual üzrə eyni cavabı verir.
        $resourceTitles = TaskResource::getEloquentQuery()->pluck('title')->all();
        $this->assertContains('Öz layihəmin gecikmiş işi', $resourceTitles);
        $this->assertNotContains('Yad layihənin gecikmiş işi', $resourceTitles);

        // Sahibkar bütün studiyaya baxır — filtr rola görə işləyir, söndürülməyib.
        $this->asUser('owner');
        $this->assertSame(
            '2',
            collect((new Dashboard)->stats())->firstWhere('label', 'Gecikmiş tapşırıqlar')['value'],
            'Sahibkar hər iki gecikmiş işi sayır.'
        );
    }

    /** MƏQSƏD: Attention ekranı yalnız Analitika icazəsi olanlara açılmalıdır. */
    public function test_attention_access_follows_the_matrix(): void
    {
        $this->asUser('designer');
        $this->assertFalse(Attention::canAccess(), 'Dizaynerin matrisində Analitika = Yoxdur.');

        $this->asUser('owner');
        $this->assertTrue(Attention::canAccess());

        $this->asUser('project_manager');
        $this->assertTrue(Attention::canAccess());
    }

    // =====================================================================
    // 7. TƏQVİM (Calendar)
    // =====================================================================

    /** MƏQSƏD: hadisələr düzgün tarixdə və düzgün mənbədən görünməlidir. */
    public function test_calendar_shows_each_source_on_the_right_date(): void
    {
        $this->task(['deadline' => today()->addDays(3), 'title' => 'Təqvim tapşırığı']);
        $this->w->project->stages()->create([
            'name' => 'Təqvim mərhələsi', 'position' => 4, 'weight' => 1,
            'status' => StageStatus::InProgress, 'date_plan_end' => today()->addDays(4),
        ]);

        $owner = $this->asUser('owner');

        $events = collect($this->actingAs($owner)->getJson(route('calendar.events', [
            'start' => today()->startOfMonth()->toDateString(),
            'end' => today()->endOfMonth()->addMonth()->toDateString(),
        ]))->assertOk()->json());

        $task = $events->firstWhere('title', '✓ Təqvim tapşırığı');
        $this->assertNotNull($task, 'Tapşırıq son tarixi təqvimdə olmalıdır.');
        $this->assertSame(today()->addDays(3)->toDateString(), $task['start']);
        $this->assertTrue($task['allDay']);

        $stage = $events->firstWhere('title', '▪ Təqvim mərhələsi');
        $this->assertNotNull($stage);
        $this->assertSame(today()->addDays(4)->toDateString(), $stage['start']);
    }

    /** MƏQSƏD: boş ayda təqvim boş qalmalı, xəta verməməlidir. */
    public function test_calendar_empty_month(): void
    {
        $owner = $this->asUser('owner');

        $this->actingAs($owner)->getJson(route('calendar.events', [
            'start' => today()->addYears(5)->startOfMonth()->toDateString(),
            'end' => today()->addYears(5)->endOfMonth()->toDateString(),
        ]))->assertOk()->assertExactJson([]);
    }

    /** MƏQSƏD: pul görməyən rol təqvimdə məbləğ görməməlidir. */
    public function test_calendar_hides_money_from_designer(): void
    {
        $this->w->project->payments()->create([
            'title' => 'Gizli ödəniş', 'amount' => 9999,
            'status' => PaymentStatus::Pending->value, 'due_date' => today()->addDays(2),
        ]);

        $designer = $this->asUser('designer');

        $titles = collect($this->actingAs($designer)->getJson(route('calendar.events', [
            'start' => today()->startOfMonth()->toDateString(),
            'end' => today()->endOfMonth()->toDateString(),
        ]))->assertOk()->json())->pluck('title')->implode(' | ');

        $this->assertStringNotContainsStringQuietly('9999', $titles, 'Dizayner ödəniş məbləğini görməməlidir.');
        $this->assertStringNotContainsStringQuietly('Gizli ödəniş', $titles, 'Dizayner ödəniş adını görməməlidir.');

        // Sahibkar isə görür — filtr rola görə işləyir, ümumiyyətlə söndürülməyib.
        $owner = $this->asUser('owner');
        $ownerTitles = collect($this->actingAs($owner)->getJson(route('calendar.events', [
            'start' => today()->startOfMonth()->toDateString(),
            'end' => today()->endOfMonth()->toDateString(),
        ]))->assertOk()->json())->pluck('title')->implode(' | ');

        $this->assertStringContainsStringQuietly('Gizli ödəniş', $ownerTitles, 'Sahibkar ödənişi görməlidir.');
    }

    /**
     * QA TAPINTI [ORTA] — DÜZƏLDİLDİ: təqvim də `whereHas('project')` ilə süzür
     * (Attention ilə eyni həll), ona görə silinmiş layihənin tapşırığı artıq
     * hadisə axınında görünmür. Layihə diri olduqca eyni tapşırıq təqvimdədir.
     */
    public function test_calendar_hides_task_of_deleted_project(): void
    {
        $this->task(['deadline' => today()->addDay(), 'title' => 'Ölü layihə tapşırığı']);
        $owner = $this->asUser('owner');

        $titles = fn () => collect($this->actingAs($owner)->getJson(route('calendar.events', [
            'start' => today()->startOfMonth()->toDateString(),
            'end' => today()->endOfMonth()->toDateString(),
        ]))->assertOk()->json())->pluck('title')->all();

        $this->assertContains('✓ Ölü layihə tapşırığı', $titles(), 'Layihə diri ikən tapşırıq təqvimdədir.');

        $this->w->project->delete();

        $this->assertNotContains('✓ Ölü layihə tapşırığı', $titles(), 'Silinmiş layihənin tapşırığı təqvimdən çıxır.');
    }

    /** MƏQSƏD: görüş vaxtı saat qurşağı sürüşməsi olmadan qaytarılmalıdır. */
    public function test_calendar_meeting_time_has_no_timezone_drift(): void
    {
        $startsAt = today()->addDays(2)->setTime(14, 30);

        Meeting::create([
            'project_id' => $this->w->project->id,
            'title' => 'Görüş',
            'starts_at' => $startsAt,
        ]);

        $owner = $this->asUser('owner');

        $events = collect($this->actingAs($owner)->getJson(route('calendar.events', [
            'start' => today()->startOfMonth()->toDateString(),
            'end' => today()->endOfMonth()->addMonth()->toDateString(),
        ]))->assertOk()->json());

        $meeting = $events->firstWhere('title', '📅 Görüş');
        $this->assertNotNull($meeting);
        $this->assertSame('14:30', Carbon::parse($meeting['start'])->format('H:i'), 'Saat sürüşməməlidir.');
    }

    // =====================================================================
    // 8. ÇAT (ChatCenter)
    // =====================================================================

    /** MƏQSƏD: söhbətlər layihələr arasında sızmamalıdır. */
    public function test_chat_does_not_leak_between_projects(): void
    {
        $chat = app(ChatService::class);

        $chat->send($this->w->otherProject, $this->w->user('owner'), 'Yad layihənin mesajı');

        $designer = $this->asUser('designer');

        $ids = $chat->staffProjectIds($designer);
        $this->assertContains($this->w->project->id, $ids);
        $this->assertNotContains($this->w->otherProject->id, $ids, 'Üzvü olmadığı layihə çat siyahısına düşməməlidir.');

        $titles = $chat->conversations($designer, $ids)->pluck('project.name')->all();
        $this->assertNotContains($this->w->otherProject->name, $titles);
    }

    /** MƏQSƏD: oxunmamış sayğacı yalnız başqasının yeni mesajını saymalıdır. */
    public function test_unread_counter_excludes_own_messages_and_resets_on_read(): void
    {
        $chat = app(ChatService::class);
        $designer = $this->asUser('designer');

        // StudioWorld-dən 1 mesaj (menecerdən) var.
        $this->assertSame([$this->w->project->id => 1], $chat->unreadCounts($designer, [$this->w->project->id]));

        // Öz mesajım sayğacı artırmır.
        $chat->send($this->w->project, $designer, 'Mənim cavabım');
        $this->assertSame([$this->w->project->id => 1], $chat->unreadCounts($designer, [$this->w->project->id]));

        // Başqasının mesajı artırır.
        $chat->send($this->w->project, $this->w->user('owner'), 'Sahibdən');
        $this->assertSame([$this->w->project->id => 2], $chat->unreadCounts($designer, [$this->w->project->id]));

        // Oxunmuş işarələnəndə sıfırlanır.
        $last = $this->w->project->chatMessages()->max('id');
        $chat->markRead($this->w->project, $designer, $last);
        $this->assertSame([], $chat->unreadCounts($designer, [$this->w->project->id]));
    }

    /** MƏQSƏD: yad layihənin çatı URL ilə də açılmamalıdır. */
    public function test_chat_center_blocks_foreign_project_by_url(): void
    {
        $this->asUser('designer');

        $this->get(ChatCenter::getUrl().'?project='.$this->w->otherProject->id)->assertForbidden();
    }

    // =====================================================================
    // 9. SƏNƏD VƏ FAYLLAR
    // =====================================================================

    /** MƏQSƏD: görünürlük bayrağı müştəriyə nəyin çatdığını idarə etməlidir. */
    public function test_file_visibility_flag_separates_internal_and_shared(): void
    {
        $visible = ProjectFile::clientVisible()->pluck('id')->all();

        $this->assertContains($this->w->sharedFile->id, $visible);
        $this->assertNotContains($this->w->internalFile->id, $visible, 'Daxili fayl müştəriyə görünməməlidir.');

        $this->assertTrue($this->w->clientDocument->visible_to_client);
        $this->assertFalse($this->w->internalDocument->visible_to_client);
    }

    /** MƏQSƏD: fayl endirmə yolu path traversal-a icazə verməməlidir. */
    public function test_file_download_rejects_path_traversal(): void
    {
        Storage::fake('public');

        $evil = ProjectFile::create([
            'project_id' => $this->w->project->id,
            'category' => 'plan',
            'visibility' => FileVisibility::Internal->value,
            'title' => 'Zərərli',
            'file_path' => '../../../.env',
        ]);

        $owner = $this->asUser('owner');

        $response = $this->actingAs($owner)->get(route('files.download', $evil));

        $this->assertContains($response->getStatusCode(), [404, 500], 'Kənara çıxan yol fayl qaytarmamalıdır.');
    }

    /** MƏQSƏD: yüklənmiş fayl yalnız icazəsi olana verilməlidir. */
    public function test_file_download_is_authorised(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put($this->w->internalFile->file_path, 'cizgi');

        $owner = $this->asUser('owner');
        $this->actingAs($owner)->get(route('files.download', $this->w->internalFile))->assertOk();

        // Üzvü olmayan vizualizator eyni faylı ala bilməməlidir.
        $visualizer = $this->w->user('visualizer');
        AccessMatrix::flushCache();
        $this->actingAs($visualizer)->get(route('files.download', $this->w->internalFile))->assertForbidden();
    }

    /**
     * QA TAPINTI [ORTA]: qeyd silinəndə fayl diskdən SİLİNMİR — nə ProjectFile,
     * nə Document üçün observer/booted hook var. Yer dolur, silinmiş «daxili»
     * cizgi diskdə qalır.
     */
    public function test_deleting_a_file_record_leaves_the_blob_on_disk(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put($this->w->internalFile->file_path, 'cizgi');

        $path = $this->w->internalFile->file_path;
        $this->w->internalFile->delete();

        $this->assertDatabaseMissing('project_files', ['file_path' => $path]);
        $this->assertTrue(Storage::disk('public')->exists($path), 'Fayl diskdə qalır.');
    }

    /**
     * QA TAPINTI [ORTA]: `Document` modelində versiya anlayışı yoxdur — nə
     * `version` sütunu, nə `versions()` əlaqəsi. Versiyalaşma yalnız
     * `Deliverable` üçün qurulub; müqavilə/akt yeniləndikdə köhnə fayl itir.
     */
    public function test_documents_have_no_versioning(): void
    {
        $doc = $this->w->clientDocument;

        $this->assertFalse(method_exists($doc, 'versions'));
        $this->assertNotContains('version', $doc->getFillable());
        $this->assertFalse(Schema::hasColumn('documents', 'version'));

        // Faylın dəyişdirilməsi köhnə yolu heç yerdə saxlamır.
        $old = $doc->file_path;
        $doc->update(['file_path' => 'docs/yeni-muqavile.pdf']);
        $this->assertSame('docs/yeni-muqavile.pdf', $doc->fresh()->file_path);
        $this->assertDatabaseMissing('documents', ['file_path' => $old]);
    }

    // =====================================================================
    // 10. PUNCH LIST / DECISION LOG / CHANGE REQUEST
    // =====================================================================

    /** MƏQSƏD: punch list qüsuru açılışdan bağlanışa qədər izlənməlidir. */
    public function test_punch_list_issue_lifecycle(): void
    {
        $issue = PunchListIssue::create([
            'project_id' => $this->w->project->id,
            'room' => 'Mətbəx',
            'title' => 'Kafel çatlayıb',
            'responsible_user_id' => $this->w->user('procurement')->id,
            'due_date' => today()->subDay(),
        ]);

        $this->assertSame(PunchIssueStatus::Open, $issue->status, 'Yeni qüsur «açıq» olmalıdır.');
        $this->assertSame($this->w->project->id, $issue->project->id);

        $issue->update(['status' => PunchIssueStatus::Assigned]);
        $issue->update(['status' => PunchIssueStatus::ReadyForReview, 'resolved_photo_url' => 'photos/fixed.jpg']);
        $issue->update(['status' => PunchIssueStatus::Closed]);
        $this->assertSame(PunchIssueStatus::Closed, $issue->fresh()->status);

        // QA TAPINTI [ORTA]: PunchListIssue-da vəziyyət maşını YOXDUR —
        // ChangeRequest-dəki `transitionTo()` qoruyucusunun qarşılığı olmadığına
        // görə bağlanmış qüsur birbaşa «açıq»a qaytarıla bilir.
        $this->assertFalse(method_exists($issue, 'transitionTo'));
        $issue->update(['status' => PunchIssueStatus::Open]);
        $this->assertSame(PunchIssueStatus::Open, $issue->fresh()->status);

        // QA TAPINTI [KİÇİK]: punch list-də gecikmə hesabı yoxdur —
        // `due_date` keçsə də modelin `isOverdue()` metodu mövcud deyil və
        // Attention ekranında ayrıca blok yoxdur.
        $this->assertFalse(method_exists($issue, 'isOverdue'));
        $blocks = collect(app(Attention::class)->blocks())->pluck('key')->all();
        $this->assertNotContains('punch', $blocks);
    }

    /** MƏQSƏD: qərar jurnalı «kim, nə vaxt, nəyə görə» sualını saxlamalıdır. */
    public function test_decision_log_records_who_and_when(): void
    {
        $decision = ProjectDecision::create([
            'project_id' => $this->w->project->id,
            'title' => 'Mətbəx rəngi',
            'source' => DecisionSource::Meeting->value,
            'decision' => 'Ağ mat seçildi',
            'made_by_user_id' => $this->w->user('project_manager')->id,
            'decided_at' => now(),
            'client_approved' => true,
        ]);

        $this->assertSame('Ağ mat seçildi', $decision->decision);
        $this->assertTrue($decision->client_approved);
        $this->assertSame($this->w->user('project_manager')->id, $decision->madeBy->id);
        // Layihənin qərarları ən yenidən köhnəyə sıralanır.
        $this->assertSame($decision->id, $this->w->project->decisions()->first()->id);
    }

    /** MƏQSƏD: dəyişiklik sorğusu nömrələnməli və qanunsuz keçidi bloklamalıdır. */
    public function test_change_request_numbering_and_state_machine(): void
    {
        $first = ChangeRequest::create([
            'project_id' => $this->w->project->id,
            'title' => 'Divar yerinin dəyişməsi',
            'schedule_impact_days' => 5,
            'cost_impact' => 1200,
        ]);

        $this->assertSame('CR-'.$this->w->project->id.'-001', $first->number);
        $this->assertSame(ChangeRequestStatus::Draft, $first->status);

        $second = ChangeRequest::create(['project_id' => $this->w->project->id, 'title' => 'İkinci']);
        $this->assertSame('CR-'.$this->w->project->id.'-002', $second->number);

        // QA TAPINTI [ORTA]: nömrə təkrar verilir. Model şərhində «Derived from
        // the highest number issued, not from count()» yazılıb, amma sorğu
        // `orderByDesc('id')` ilə QALAN sətirlərə baxır — sonuncu CR silinəndə
        // növbəti yaradılan eyni nömrəni alır. ChangeRequest soft-delete
        // etmədiyinə görə bu real ssenaridir (Filament-də Sil düyməsi var).
        $second->delete();
        $third = ChangeRequest::create(['project_id' => $this->w->project->id, 'title' => 'Üçüncü']);
        $this->assertSame(
            'CR-'.$this->w->project->id.'-002',
            $third->number,
            'Silinmiş CR-002 nömrəsi yenidən verildi — sənəd izi pozulur.'
        );

        // Qanunsuz keçid bloklanır.
        try {
            $first->transitionTo(ChangeRequestStatus::Completed);
            $this->fail('draft → completed keçidi qadağan olmalıdır.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Qadağan keçid', $e->getMessage());
        }

        // Qanuni axın işləyir.
        $first->transitionTo(ChangeRequestStatus::ImpactAssessment);
        $first->transitionTo(ChangeRequestStatus::WaitingInternalApproval);
        $first->transitionTo(ChangeRequestStatus::WaitingClientApproval);
        $first->transitionTo(ChangeRequestStatus::Approved);
        $this->assertSame(ChangeRequestStatus::Approved, $first->fresh()->status);
    }

    /**
     * QA TAPINTI [ORTA]: təsdiqlənmiş dəyişiklik sorğusunun vaxt/məbləğ təsiri
     * layihəyə AVTOMATİK tətbiq olunmur — `schedule_impact_days` layihənin
     * `deadline`-ını, `cost_impact` isə `budget_plan`-ı dəyişmir.
     */
    public function test_approved_change_request_does_not_move_project_plan(): void
    {
        $this->w->project->update(['deadline' => today()->addDays(30), 'budget_plan' => 10000]);
        $deadlineBefore = $this->w->project->fresh()->deadline->toDateString();

        $cr = ChangeRequest::create([
            'project_id' => $this->w->project->id,
            'title' => 'Əlavə otaq',
            'schedule_impact_days' => 10,
            'cost_impact' => 5000,
        ]);

        $cr->transitionTo(ChangeRequestStatus::ImpactAssessment);
        $cr->transitionTo(ChangeRequestStatus::WaitingInternalApproval);
        $cr->transitionTo(ChangeRequestStatus::WaitingClientApproval);
        $cr->transitionTo(ChangeRequestStatus::Approved);

        $project = $this->w->project->fresh();
        $this->assertSame($deadlineBefore, $project->deadline->toDateString(), 'Təhvil müddəti dəyişməyib.');
        $this->assertSame('10000.00', (string) $project->budget_plan, 'Plan büdcə dəyişməyib.');
    }
}
