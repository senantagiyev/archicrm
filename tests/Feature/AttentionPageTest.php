<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\BriefStatus;
use App\Models\Approval;
use App\Models\Brief;
use App\Models\BudgetLine;
use App\Models\Payment;
use App\Models\Stage;
use App\Models\Task;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * «Bu gün nəyə diqqət lazımdır» ekranı.
 *
 * Bütün yoxlamalar real HTTP üzərindən gedir — middleware yığını, studiya
 * konteksti (SetTenant) və Filament-in icazə yoxlaması da bu yolla işə düşür;
 * birbaşa sinif çağırışı bunların heç birini sınaqdan keçirməzdi.
 */
class AttentionPageTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = StudioWorld::make('alfa');
        $this->beta = StudioWorld::make('beta');

        $this->seedAttentionItems($this->alfa);
    }

    private function url(): string
    {
        return route('filament.app.pages.attention');
    }

    /**
     * Hər sorğu təmiz sessiya ilə başlayır: bir testin içində istifadəçi
     * dəyişdikdə AuthenticateSession köhnə sessiyanı etibarsız sayır və sorğu
     * login səhifəsinə yönlənir — yoxlamaq istədiyimiz isə icazədir.
     */
    private function visit(StudioWorld $world, string $role): TestResponse
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return $this->actingAs($world->user($role))->get($this->url());
    }

    private function pageFor(StudioWorld $world, string $role): string
    {
        $response = $this->visit($world, $role);
        $response->assertOk();

        return $response->getContent();
    }

    /**
     * Hər blok üçün bir «gecikmiş» element. Adlar qəsdən unikaldır ki, səhifənin
     * başqa yerindəki təsadüfi mətnlə qarışmasın.
     */
    private function seedAttentionItems(StudioWorld $world): void
    {
        $prefix = strtoupper(explode(' ', $world->tenant->name)[0]);

        app(TenantContext::class)->actingAs($world->tenant->id, function () use ($world, $prefix): void {
            Stage::create([
                'project_id' => $world->project->id,
                'name' => $prefix.'_GECIKMIS_MERHELE',
                'position' => 9,
                'weight' => 1,
                'status' => 'in_progress',
                'date_plan_end' => today()->subDays(3),
            ]);

            Task::create([
                'project_id' => $world->project->id,
                'stage_id' => $world->stage->id,
                'title' => $prefix.'_GECIKMIS_TAPSIRIQ',
                'status' => 'todo',
                'deadline' => today()->subDays(2),
            ]);

            Payment::create([
                'project_id' => $world->project->id,
                'title' => $prefix.'_GECIKMIS_ODENIS',
                'amount' => 1000,
                'status' => 'pending',
                'due_date' => today()->subDays(5),
            ]);

            Brief::create([
                'project_id' => $world->project->id,
                'status' => BriefStatus::Submitted->value,
                'submitted_at' => now()->subDay(),
            ]);

            // Üç razılaşdırma: müddəti keçmiş, vaxtı hələ çatmamış və artıq
            // qərar verilmiş. Blok yalnız gözləyənləri göstərməlidir.
            $this->approvalOn($world, $prefix.'_GECIKMIS_RAZILASDIRMA', ApprovalStatus::Pending, today()->subDay());
            $this->approvalOn($world, $prefix.'_VAXTINDA_RAZILASDIRMA', ApprovalStatus::Pending, today()->addWeek());
            $this->approvalOn($world, $prefix.'_QERAR_VERILMIS_RAZILASDIRMA', ApprovalStatus::Approved, today()->subDay());
        });
    }

    private function approvalOn(StudioWorld $world, string $workType, ApprovalStatus $status, \DateTimeInterface $respondBy): void
    {
        $line = BudgetLine::create([
            'project_id' => $world->project->id,
            'work_type' => $workType,
            'unit' => 'm2',
            'qty' => 1,
            'work_price' => 10,
            'material_price' => 5,
            'position' => 9,
        ]);

        Approval::create([
            'approvable_type' => 'budget_line',
            'approvable_id' => $line->id,
            'project_id' => $world->project->id,
            'requested_by_user_id' => $world->user('project_manager')->id,
            'client_user_id' => $world->portalUser->id,
            'status' => $status->value,
            'respond_by' => $respondBy,
            'decided_at' => $status === ApprovalStatus::Approved ? now() : null,
        ]);
    }

    // ── Studiya izolyasiyası — ən vacib yoxlama ──────────────────────────────

    public function test_another_studios_overdue_items_never_appear(): void
    {
        $this->seedAttentionItems($this->beta);

        $content = $this->pageFor($this->beta, 'owner');

        foreach ([
            'ALFA_GECIKMIS_MERHELE',
            'ALFA_GECIKMIS_TAPSIRIQ',
            'ALFA_GECIKMIS_ODENIS',
            'ALFA_GECIKMIS_RAZILASDIRMA',
            $this->alfa->project->name,
        ] as $needle) {
            $this->assertStringNotContainsStringQuietly(
                $needle,
                $content,
                'Beta studiyasının «diqqət» ekranında Alfa studiyasının elementi göründü: '.$needle,
            );
        }

        // Müsbət tərəf: öz elementini görür, yəni ekran sadəcə boş qalmayıb.
        $this->assertStringContainsStringQuietly(
            'BETA_GECIKMIS_MERHELE',
            $content,
            'Beta studiyası öz gecikmiş mərhələsini görmədi — yoxlama mənasızlaşır.',
        );
    }

    public function test_a_studio_with_nothing_seeded_sees_only_calm_messages(): void
    {
        // Beta-ya heç nə əlavə edilməyib: Alfa-nın sətirləri sızsaydı, bu blok
        // boş qalmazdı.
        $content = $this->pageFor($this->beta, 'owner');

        $this->assertStringContainsStringQuietly(
            'Gecikmiş mərhələ yoxdur.',
            $content,
            'Boş blok üçün sakit mesaj göstərilmədi.',
        );
    }

    // ── İcazələr ─────────────────────────────────────────────────────────────

    public function test_a_role_without_analytics_access_cannot_open_the_page(): void
    {
        foreach (['designer', 'visualizer', 'procurement'] as $role) {
            $response = $this->visit($this->alfa, $role);

            $this->assertContains(
                $response->status(),
                [403, 404],
                'Analytics icazəsi olmayan rol («'.$role.'») ekranı açdı.',
            );
        }
    }

    public function test_the_owner_and_the_accountant_can_open_the_page(): void
    {
        foreach (['owner', 'accountant'] as $role) {
            $this->visit($this->alfa, $role)->assertOk();
        }
    }

    public function test_a_project_manager_sees_only_their_own_projects(): void
    {
        // PM «öz layihələri» rejimindədir; otherProject-in meneceri sahibkardır
        // və PM orada üzv deyil, deməki oradakı gecikmə onun siyahısına düşmür.
        app(TenantContext::class)->actingAs($this->alfa->tenant->id, fn () => Stage::create([
            'project_id' => $this->alfa->otherProject->id,
            'name' => 'YAD_LAYIHE_MERHELESI',
            'position' => 9,
            'weight' => 1,
            'status' => 'in_progress',
            'date_plan_end' => today()->subDays(3),
        ]));

        $content = $this->pageFor($this->alfa, 'project_manager');

        $this->assertStringContainsStringQuietly(
            'ALFA_GECIKMIS_MERHELE',
            $content,
            'Layihə meneceri öz layihəsinin gecikmiş mərhələsini görmədi.',
        );

        $this->assertStringNotContainsStringQuietly(
            'YAD_LAYIHE_MERHELESI',
            $content,
            'Layihə menecerinin siyahısında üzv olmadığı layihənin mərhələsi göründü.',
        );
    }

    // ── Razılaşdırma bloku ───────────────────────────────────────────────────

    public function test_an_overdue_approval_is_listed_and_flagged(): void
    {
        $content = $this->pageFor($this->alfa, 'owner');

        $this->assertStringContainsStringQuietly(
            'ALFA_GECIKMIS_RAZILASDIRMA',
            $content,
            'Müddəti keçmiş razılaşdırma blokda görünmədi.',
        );

        $this->assertStringContainsStringQuietly(
            'Cavab müddəti keçib',
            $content,
            'Müddəti keçmiş razılaşdırma ayrıca vurğulanmadı.',
        );
    }

    public function test_a_decided_approval_is_not_listed(): void
    {
        $content = $this->pageFor($this->alfa, 'owner');

        // Qərar verilmiş razılaşdırma artıq heç kimi gözlətmir — siyahıda ona yer yoxdur.
        $this->assertStringNotContainsStringQuietly(
            'ALFA_QERAR_VERILMIS_RAZILASDIRMA',
            $content,
            'Artıq təsdiqlənmiş razılaşdırma «cavab gözləyir» blokunda göründü.',
        );
    }

    public function test_an_on_time_approval_is_listed_without_the_overdue_flag(): void
    {
        // Vaxtı çatmamış razılaşdırma gözləyənlər sırasındadır, amma gecikmiş
        // kimi işarələnməməlidir — əks halda vurğu mənasını itirir.
        app(TenantContext::class)->actingAs($this->alfa->tenant->id, function (): void {
            Approval::query()
                ->where('status', ApprovalStatus::Pending->value)
                ->whereNotNull('respond_by')
                ->whereDate('respond_by', '<', today())
                ->delete();
        });

        $content = $this->pageFor($this->alfa, 'owner');

        $this->assertStringContainsStringQuietly(
            'ALFA_VAXTINDA_RAZILASDIRMA',
            $content,
            'Vaxtında olan gözləyən razılaşdırma siyahıdan düşdü.',
        );

        $this->assertStringNotContainsStringQuietly(
            'Cavab müddəti keçib',
            $content,
            'Vaxtı çatmamış razılaşdırma gecikmiş kimi işarələndi.',
        );
    }

    // ── Digər bloklar ────────────────────────────────────────────────────────

    public function test_every_block_shows_its_overdue_item(): void
    {
        $content = $this->pageFor($this->alfa, 'owner');

        foreach ([
            'ALFA_GECIKMIS_MERHELE' => 'mərhələ',
            'ALFA_GECIKMIS_TAPSIRIQ' => 'tapşırıq',
            'ALFA_GECIKMIS_ODENIS' => 'ödəniş',
        ] as $needle => $label) {
            $this->assertStringContainsStringQuietly(
                $needle,
                $content,
                'Gecikmiş '.$label.' blokda görünmədi.',
            );
        }

        $this->assertStringContainsStringQuietly(
            'Cavab gözləyən briflər',
            $content,
            'Brif bloku render olunmadı.',
        );
    }

    public function test_a_finished_task_is_not_listed(): void
    {
        app(TenantContext::class)->actingAs($this->alfa->tenant->id, fn () => Task::create([
            'project_id' => $this->alfa->project->id,
            'stage_id' => $this->alfa->stage->id,
            'title' => 'ALFA_BITMIS_TAPSIRIQ',
            'status' => 'done',
            'deadline' => today()->subDays(4),
            'completed_at' => now(),
        ]));

        $content = $this->pageFor($this->alfa, 'owner');

        $this->assertStringNotContainsStringQuietly(
            'ALFA_BITMIS_TAPSIRIQ',
            $content,
            'Bitmiş tapşırıq «gecikmiş» blokuna düşdü.',
        );
    }
}
