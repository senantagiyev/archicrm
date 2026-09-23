<?php

namespace Tests\Feature\Fix;

use App\Enums\ProjectStatus;
use App\Enums\StageStatus;
use App\Enums\TaskStatus;
use App\Filament\Pages\Attention;
use App\Filament\Pages\TaskPlanner;
use App\Filament\Resources\TaskResource;
use App\Filament\Widgets\MyTasksWidget;
use App\Models\Stage;
use App\Models\Task;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\RoleSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA tapıntılarının regresiya qoruması: tapşırıq görünürlüyü.
 *
 * Qayda bir cümlədədir — tapşırıq sətri görünür, ƏGƏR rol matrisdə «yalnız öz
 * layihələri» deyilsə (sahibkar, mühasib) VƏ YA istifadəçi tapşırığın
 * layihəsinin ÜZVÜdürsə. İcraçı olmaq üzvlüyü əvəz etmir. Hər test həm icazə
 * verilən, həm rədd edilən tərəfi yoxlayır ki, düzəliş icazəni genişlətməsin.
 *
 * StudioWorld-də üzvlük belədir: `project` — menecer, dizayner, komplektləşdirici
 * üzvdür (vizualizator YOX); `otherProject` — ortaq üzv yoxdur.
 */
class TaskVisibilityFixTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $w;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        AccessMatrix::flushCache();

        $this->w = StudioWorld::make('alpha');

        app(TenantContext::class)->set($this->w->tenant->id);
        Filament::setTenant(null, true);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    // ── Köməkçilər ───────────────────────────────────────────────────────────

    private function asUser(string $role): User
    {
        $user = $this->w->user($role);
        $this->actingAs($user);
        AccessMatrix::flushCache();

        return $user;
    }

    /** Üzv olunan layihədə (`project`) tapşırıq. */
    private function task(array $attributes = []): Task
    {
        return Task::create(array_merge([
            'project_id' => $this->w->project->id,
            'stage_id' => $this->w->stage->id,
            'title' => 'Tapşırıq',
            'status' => TaskStatus::Todo->value,
        ], $attributes));
    }

    /** Ortaq üzvü olmayan layihədə (`otherProject`) tapşırıq. */
    private function foreignTask(array $attributes = []): Task
    {
        $stage = Stage::firstOrCreate(
            ['project_id' => $this->w->otherProject->id, 'position' => 1],
            ['name' => 'Yad mərhələ', 'weight' => 1, 'status' => StageStatus::InProgress->value],
        );

        return Task::create(array_merge([
            'project_id' => $this->w->otherProject->id,
            'stage_id' => $stage->id,
            'title' => 'Yad layihənin tapşırığı',
            'status' => TaskStatus::Todo->value,
        ], $attributes));
    }

    /** @return array<int, string> */
    private function visibleTitles(): array
    {
        return TaskResource::getEloquentQuery()->pluck('title')->all();
    }

    /** @return array<string, array<string, mixed>> */
    private function attentionBlocks(): array
    {
        return collect(app(Attention::class)->blocks())->keyBy('key')->all();
    }

    // ── 1. Menecer öz layihəsinin bütün tapşırıqlarını görür ────────────────

    public function test_project_manager_sees_own_project_tasks_assigned_to_others(): void
    {
        $pm = $this->w->user('project_manager');
        $designerTask = $this->task([
            'assignee_user_id' => $this->w->user('designer')->id,
            'title' => 'Dizaynerin işi',
        ]);

        $this->asUser('project_manager');

        $this->assertContains('Dizaynerin işi', $this->visibleTitles());
        $this->assertTrue($pm->can('view', $designerTask), 'Üzv olduğu layihənin tapşırığı menecerə açıq olmalıdır.');
        $this->assertTrue($pm->can('update', $designerTask), 'Matrisdə Mərhələ/Tapşırıq = Tam — redaktə də açıqdır.');
    }

    /** Əks tərəf: üzvlük yoxdursa, menecerin «Tam» səviyyəsi də kömək etmir. */
    public function test_project_manager_does_not_see_tasks_of_a_project_they_are_not_member_of(): void
    {
        $pm = $this->w->user('project_manager');
        $foreign = $this->foreignTask(['assignee_user_id' => $this->w->user('owner')->id]);

        $this->assertFalse($this->w->otherProject->hasMember($pm), 'Test ön şərti: menecer bu layihənin üzvü deyil.');

        $this->asUser('project_manager');

        $this->assertNotContains('Yad layihənin tapşırığı', $this->visibleTitles());
        $this->assertFalse($pm->can('view', $foreign));
        $this->assertFalse($pm->can('update', $foreign));
    }

    // ── 2. Təyinat üzvlüyü əvəz etmir ───────────────────────────────────────

    public function test_assignment_alone_does_not_open_a_task_in_a_foreign_project(): void
    {
        $designer = $this->w->user('designer');
        $foreign = $this->foreignTask(['assignee_user_id' => $designer->id]);

        $this->asUser('designer');

        $this->assertFalse($designer->can('view', $this->w->otherProject), 'Test ön şərti: layihənin özü bağlıdır.');
        $this->assertFalse($designer->can('view', $foreign), 'Layihə bağlı, tapşırıq açıq qalmamalıdır.');
        $this->assertFalse($designer->can('update', $foreign));
        $this->assertFalse($designer->can('delete', $foreign));
        $this->assertNotContains('Yad layihənin tapşırığı', $this->visibleTitles());
    }

    /** İcazə verilən tərəf: üzv olduğu layihədə dizayner həm görür, həm redaktə edir. */
    public function test_designer_keeps_access_inside_a_project_they_are_member_of(): void
    {
        $designer = $this->w->user('designer');
        $own = $this->task(['assignee_user_id' => $designer->id, 'title' => 'Dizaynerin öz işi']);

        $this->asUser('designer');

        $this->assertContains('Dizaynerin öz işi', $this->visibleTitles());
        $this->assertTrue($designer->can('view', $own));
        $this->assertTrue($designer->can('update', $own));
    }

    /**
     * Müəllif güzəşti də üzvlüklə məhdudlaşır — silmə istiqamətində boşluq
     * qalmasın.
     */
    public function test_authoring_a_task_does_not_open_a_foreign_project(): void
    {
        $designer = $this->w->user('designer');
        $foreign = $this->foreignTask(['author_user_id' => $designer->id]);
        $own = $this->task(['author_user_id' => $designer->id, 'title' => 'Öz yaratdığım']);

        $this->asUser('designer');

        $this->assertFalse($designer->can('delete', $foreign), 'Yad layihədə müəllif olmaq silmə hüququ vermir.');
        $this->assertTrue($designer->can('delete', $own), 'Üzv olduğu layihədə müəllif güzəşti qalır.');
    }

    // ── İcazə GENİŞLƏNMİR ───────────────────────────────────────────────────

    /**
     * Vizualizator StudioWorld-də HEÇ bir layihənin üzvü deyil — nə `project`,
     * nə `otherProject`. Deməli düzəlişdən sonra da heç bir tapşırıq görməməlidir,
     * hətta ona təyin edilənləri də.
     */
    public function test_visualizer_who_is_member_of_no_project_sees_nothing(): void
    {
        $viz = $this->w->user('visualizer');
        $assigned = $this->task(['assignee_user_id' => $viz->id, 'title' => 'Vizualizatora verilən']);
        $foreign = $this->foreignTask(['assignee_user_id' => $viz->id]);

        $this->asUser('visualizer');

        $this->assertSame([], $this->visibleTitles(), 'Üzv olmadığı layihələrdə heç nə görünməməlidir.');
        $this->assertFalse($viz->can('view', $assigned));
        $this->assertFalse($viz->can('view', $foreign));
    }

    /** Komplektləşdirici `project`-in üzvüdür: orada görür, `otherProject`-də yox. */
    public function test_procurement_sees_only_the_project_they_are_member_of(): void
    {
        $procurement = $this->w->user('procurement');
        $own = $this->task(['title' => 'Üzv olduğum layihədə']);
        $foreign = $this->foreignTask();

        $this->asUser('procurement');

        $titles = $this->visibleTitles();
        $this->assertContains('Üzv olduğum layihədə', $titles);
        $this->assertNotContains('Yad layihənin tapşırığı', $titles);
        $this->assertTrue($procurement->can('view', $own));
        $this->assertFalse($procurement->can('view', $foreign));
    }

    /** Sahibkar/mühasib «öz layihələri» rolları deyil — onlarda heç nə daralmır. */
    public function test_owner_still_sees_every_project(): void
    {
        $this->task(['title' => 'Birinci layihə işi']);
        $this->foreignTask();

        $this->asUser('owner');

        $titles = $this->visibleTitles();
        $this->assertContains('Birinci layihə işi', $titles);
        $this->assertContains('Yad layihənin tapşırığı', $titles);
    }

    // ── 1+2. Planlaşdırıcı eyni qaydaya tabedir ─────────────────────────────

    public function test_task_planner_scopes_by_project_membership(): void
    {
        $this->task(['assignee_user_id' => $this->w->user('designer')->id, 'title' => 'PLAN-DIZAYNER-ISI']);
        $this->foreignTask(['title' => 'PLAN-YAD-ISI']);

        $this->asUser('project_manager');

        $titles = collect((new TaskPlanner)->getGroupedTasks())
            ->flatMap(fn ($tasks) => $tasks->pluck('title'))
            ->all();

        $this->assertContains('PLAN-DIZAYNER-ISI', $titles, 'Menecer öz layihəsinin işini planda görməlidir.');
        $this->assertNotContains('PLAN-YAD-ISI', $titles, 'Üzv olmadığı layihə planda görünməməlidir.');
    }

    public function test_task_planner_does_not_show_a_foreign_project_task_to_its_assignee(): void
    {
        $this->foreignTask(['assignee_user_id' => $this->w->user('designer')->id, 'title' => 'PLAN-YAD-TEYINAT']);

        $this->asUser('designer');

        $titles = collect((new TaskPlanner)->getGroupedTasks())
            ->flatMap(fn ($tasks) => $tasks->pluck('title'))
            ->all();

        $this->assertNotContains('PLAN-YAD-TEYINAT', $titles);
    }

    // ── 3. Son tarixi olmayan tapşırıq ──────────────────────────────────────

    public function test_task_without_deadline_is_visible_in_my_tasks_widget(): void
    {
        $designer = $this->w->user('designer');

        // StudioWorld-ün öz «Planlaşdırma» tapşırığı da tarixsizdir və
        // dizaynerə təyin edilib — sistemin özü tarixsiz iş yaradır.
        $noDeadline = $this->task([
            'assignee_user_id' => $designer->id,
            'deadline' => null,
            'title' => 'Tarixsiz',
        ]);
        $thisWeek = $this->task([
            'assignee_user_id' => $designer->id,
            'deadline' => today(),
            'title' => 'Bu həftə',
        ]);
        $farFuture = $this->task([
            'assignee_user_id' => $designer->id,
            'deadline' => today()->addWeeks(3),
            'title' => 'Gələcək',
        ]);
        $someoneElse = $this->task([
            'assignee_user_id' => $this->w->user('procurement')->id,
            'deadline' => today(),
            'title' => 'Başqasının',
        ]);

        $this->asUser('designer');

        Livewire::test(MyTasksWidget::class)
            ->assertCanSeeTableRecords([$noDeadline, $thisWeek, $this->w->task])
            ->assertCanNotSeeTableRecords([$farFuture, $someoneElse]);
    }

    /** Vidjet «mənim işim»dir, amma açılmayan sətir göstərmir. */
    public function test_my_tasks_widget_hides_tasks_from_projects_the_user_is_not_member_of(): void
    {
        $designer = $this->w->user('designer');
        $foreign = $this->foreignTask(['assignee_user_id' => $designer->id, 'deadline' => null]);

        $this->asUser('designer');

        Livewire::test(MyTasksWidget::class)
            ->assertCanSeeTableRecords([$this->w->task])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    // ── 4. Attention-dakı keçid menecerdə açılır ────────────────────────────

    public function test_the_attention_task_link_opens_for_the_manager(): void
    {
        $overdue = $this->task([
            'assignee_user_id' => $this->w->user('designer')->id,
            'deadline' => today()->subDays(3),
            'title' => 'ATTENTION-GECIKMIS',
        ]);

        $this->asUser('project_manager');

        $blocks = $this->attentionBlocks();
        $this->assertSame(1, $blocks['tasks']['count'], 'Menecer öz layihəsinin gecikmiş tapşırığını siyahıda görür.');
        $this->assertSame('ATTENTION-GECIKMIS', $blocks['tasks']['items'][0]['title']);

        $url = $blocks['tasks']['items'][0]['url'];
        $this->assertNotNull($url);

        // Siyahı ilə resursun sorğusu artıq eyni qaydaya tabedir — keçid 404
        // yerinə redaktə səhifəsini açır.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->w->user('project_manager'))->get($url)->assertOk();

        $this->assertNotNull($overdue->fresh());
    }

    /** Əks tərəf: yad layihənin gecikmiş tapşırığı menecerin siyahısına düşmür. */
    public function test_the_attention_list_skips_foreign_projects_for_the_manager(): void
    {
        $this->foreignTask(['deadline' => today()->subDays(3), 'title' => 'ATTENTION-YAD']);

        $this->asUser('project_manager');

        $blocks = $this->attentionBlocks();
        $this->assertSame(0, $blocks['tasks']['count']);
    }

    // ── 5. Silinmiş layihə ──────────────────────────────────────────────────

    public function test_a_soft_deleted_projects_tasks_leave_attention_and_the_planner(): void
    {
        $this->task(['deadline' => today()->subDays(5), 'title' => 'OLU-LAYIHE-ISI']);

        $this->w->project->delete();

        $this->asUser('owner');

        $this->assertSame(0, $this->attentionBlocks()['tasks']['count'], 'Silinmiş layihənin tapşırığı yanlış pozitivdir.');

        $titles = collect((new TaskPlanner)->getGroupedTasks())
            ->flatMap(fn ($tasks) => $tasks->pluck('title'))
            ->all();
        $this->assertNotContains('OLU-LAYIHE-ISI', $titles);
    }

    /** İcazə verilən tərəf: diri layihənin eyni tapşırığı yerindədir. */
    public function test_a_live_projects_overdue_task_stays_in_attention_and_the_planner(): void
    {
        $this->task(['deadline' => today()->subDays(5), 'title' => 'DIRI-LAYIHE-ISI']);

        $this->asUser('owner');

        $this->assertSame(1, $this->attentionBlocks()['tasks']['count']);

        $titles = collect((new TaskPlanner)->getGroupedTasks())
            ->flatMap(fn ($tasks) => $tasks->pluck('title'))
            ->all();
        $this->assertContains('DIRI-LAYIHE-ISI', $titles);
    }

    // ── 6. Arxiv statuslu layihə ────────────────────────────────────────────

    public function test_an_archived_project_leaves_attention(): void
    {
        $this->task(['deadline' => today()->subDays(3), 'title' => 'ARXIV-ISI']);
        $this->w->project->update(['status' => ProjectStatus::Archived->value]);

        $this->asUser('owner');

        $blocks = $this->attentionBlocks();

        $this->assertSame(0, $blocks['tasks']['count'], 'Arxiv layihə aktiv iş axınından çıxmalıdır.');
        $this->assertSame(0, $blocks['stages']['count'] + $blocks['approvals']['count'] + $blocks['payments']['count'] + $blocks['briefs']['count']);

        // Arxiv məxfilik deyil: sətir resursda hələ də oxunur, sadəcə «bu gün
        // diqqət lazımdır» siyahısında yer tutmur.
        $this->assertContains('ARXIV-ISI', $this->visibleTitles());
    }
}
