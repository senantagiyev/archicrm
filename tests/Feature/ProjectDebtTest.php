<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ProjectStatus;
use App\Filament\Widgets\OwnerStatsOverview;
use App\Models\Project;
use App\Services\Finance\ProjectFinanceService;
use App\Support\TenantContext;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Qalıq borc: işarəli kəmiyyətin ekranda necə oxunduğu.
 *
 * `projects.debt` = razılaşdırılmış smeta + komplektasiya − təsdiqlənmiş
 * ödənişlər. Düstur doğrudur, amma nəticə MƏNFİ ola bilər: müştəri hələ
 * rəsmiləşdirilməmiş işin qabağına pul verəndə belə olur. Portal bunu «Qalıq
 * borc −222 ₼» kimi göstərirdi, rəhbər paneli isə həmin mənfini başqa layihənin
 * real borcundan çıxırdı.
 */
class ProjectDebtTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();
        // Etiketlər tərcümə cədvəlindən gəlir; seed olunmasa `t()` açarın özünü
        // qaytarır və «portal.debt» «portal.debt_credit»-in içində görünür.
        $this->seed(TranslationSeeder::class);
        $this->studio = StudioWorld::make('debt');
    }

    /** Ödəniş var, razılaşdırılmış smeta yoxdur → bu borc deyil, avansdır. */
    public function test_a_payment_without_approved_work_is_shown_as_an_advance_not_a_negative_debt(): void
    {
        $project = $this->prepaidProject(222);

        $this->assertSame(-222.0, (float) $project->debt, 'Sütun işarəni saxlamalıdır — mühasibat üçün doğrudur.');

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.payments', $project));

        $response->assertOk();
        $response->assertSee(t('portal.debt_credit'));
        $response->assertSee('222.00');
        $response->assertDontSee('-222.00');
        $response->assertDontSee(t('portal.debt'));
    }

    /** Razılaşdırılmış iş ödənişdən çoxdursa — bu, həqiqi borcdur. */
    public function test_a_real_debt_keeps_its_label(): void
    {
        $project = $this->projectWith(approvedWork: 1000, paid: 400);

        $this->assertSame(600.0, (float) $project->debt);

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.payments', $project));

        $response->assertOk();
        $response->assertSee(t('portal.debt'));
        $response->assertSee('600.00');
    }

    /** Tam ödənilmiş layihə «borc yoxdur» deyir, sıfırı mənfi kimi göstərmir. */
    public function test_a_settled_project_says_nothing_is_outstanding(): void
    {
        $project = $this->projectWith(approvedWork: 500, paid: 500);

        $this->assertSame(0.0, (float) $project->debt);

        $response = $this->actingAs($this->studio->portalUser, 'customer')
            ->get(route('portal.payments', $project));

        $response->assertOk();
        $response->assertSee(t('portal.debt_settled'));
    }

    /** Bir layihənin avansı digərinin borcunu gizlətməməlidir. */
    public function test_an_advance_on_one_project_does_not_shrink_the_studios_total_debt(): void
    {
        $this->prepaidProject(222);
        $this->projectWith(approvedWork: 1000, paid: 0);

        $this->actingAs($this->studio->user('owner'));
        app(TenantContext::class)->set($this->studio->tenant->id);

        $totalDebt = Project::whereNotIn('status', [ProjectStatus::Archived->value])
            ->where('debt', '>', 0)
            ->sum('debt');

        // Sadə `sum()` 1000 − 222 = 778 verirdi; real borc 1000-dir.
        $this->assertSame(1000.0, (float) $totalDebt);

        $html = Livewire::test(OwnerStatsOverview::class)->html();
        $this->assertStringNotContainsString('778', $html);
    }

    /** Ödənişi olan, amma razılaşdırılmış işi olmayan ayrıca layihə. */
    private function prepaidProject(float $amount): Project
    {
        return $this->projectWith(approvedWork: 0, paid: $amount);
    }

    /**
     * Rəqəmləri nəzarətdə saxlamaq üçün hər ssenari ÖZ layihəsini qurur —
     * `StudioWorld`-ün hazır büdcə sətri və avansı cəmləri çaşdırırdı.
     */
    private function projectWith(float $approvedWork, float $paid): Project
    {
        return app(TenantContext::class)->actingAs($this->studio->tenant->id, function () use ($approvedWork, $paid) {
            $project = Project::create([
                'client_id' => $this->studio->client->id,
                'name' => 'Borc ssenarisi '.uniqid(),
                'type' => 'apartment',
                'status' => ProjectStatus::Active->value,
            ]);

            if ($approvedWork > 0) {
                $line = $project->budgetLines()->create([
                    'work_type' => 'Divar', 'unit' => 'm2', 'qty' => 1,
                    'work_price' => $approvedWork, 'material_price' => 0, 'position' => 1,
                ]);

                // `approval_status` qəsdən fillable deyil — yalnız müştərinin
                // qərarı ilə dəyişir (audit HIGH-2). Testdə razılaşdırılmış
                // vəziyyəti birbaşa qururuq.
                $line->forceFill(['approval_status' => ApprovalStatus::Approved->value])->save();
            }

            if ($paid > 0) {
                $project->payments()->create([
                    'title' => 'Ödəniş', 'amount' => $paid,
                    'status' => PaymentStatus::Paid->value, 'paid_at' => now(),
                ]);
            }

            app(ProjectFinanceService::class)->recalculateDebt($project);

            return $project->fresh();
        });
    }
}
