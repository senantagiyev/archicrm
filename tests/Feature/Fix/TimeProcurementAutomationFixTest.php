<?php

namespace Tests\Feature\Fix;

use App\Filament\Resources\AutomationRuleResource\Pages\ListAutomationRules;
use App\Filament\Resources\PurchaseOrderResource\Pages\ListPurchaseOrders;
use App\Filament\Resources\TimeEntryResource\Pages\CreateTimeEntry;
use App\Filament\Resources\TimeEntryResource\Pages\ListTimeEntries;
use App\Models\AutomationRule;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Notifications\TaskOverdue;
use App\Services\Automation\AutomationEngine;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * QA tapıntılarının DÜZƏLİŞ (regresiya) testləri — vaxt uçotu, satınalma və
 * avtomatlaşdırma. Hər test bir tapıntıya uyğundur və düzəliş geri qaytarılsa
 * qırmızı olur.
 *
 * Avtomatlaşdırma tapıntıları İKİ STUDİYA ilə sübut olunur: sızma yalnız
 * «alfa + beta» qurğusunda görünür.
 */
class TimeProcurementAutomationFixTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alfa;

    private StudioWorld $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = StudioWorld::make('alpha');
        $this->beta = StudioWorld::make('beta');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    private function inTenant(StudioWorld $world, callable $callback): mixed
    {
        return app(TenantContext::class)->actingAs($world->tenant->id, $callback);
    }

    private function asStaff(StudioWorld $world, string $role): User
    {
        $user = $world->user($role);
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($world->tenant->id);
        AccessMatrix::flushCache();

        return $user;
    }

    // =====================================================================
    // 1. [CİDDİ] Üst-üstə düşən vaxt intervalları
    // =====================================================================

    /**
     * Eyni işçinin 09:00–13:00 və 10:00–12:00 qeydləri birlikdə 360 dəqiqə
     * verirdi (real iş 4 saat) və bu rəqəm rentabellik hesabatına maya dəyəri
     * kimi düşürdü. İndi ikinci qeyd bloklanır.
     */
    public function test_overlapping_time_entries_for_the_same_user_are_blocked(): void
    {
        $designer = $this->alfa->user('designer');
        $designer->forceFill(['hourly_internal_cost' => 10])->save();

        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(9, 0), 'ended_at' => now()->setTime(13, 0),
        ]));

        // Kəsişmə BAŞQA layihədə də bloklanmalıdır — işçi eyni saatda iki yerdə ola bilməz.
        try {
            $this->inTenant($this->alfa, fn () => TimeEntry::create([
                'user_id' => $designer->id, 'project_id' => $this->alfa->otherProject->id,
                'started_at' => now()->setTime(10, 0), 'ended_at' => now()->setTime(12, 0),
            ]));
            $this->fail('Üst-üstə düşən vaxt qeydi saxlanıldı.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('üst-üstə', $e->getMessage());
        }

        $total = $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $designer->id)->sum('duration_minutes'));
        $this->assertSame(240, (int) $total, 'Yalnız real 4 saat qalmalıdır.');
    }

    /** Bitişik intervallar (13:00-da bitən + 13:00-da başlayan) kəsişmə deyil — iş günü bloklanmamalıdır. */
    public function test_adjacent_and_other_users_time_entries_are_still_allowed(): void
    {
        $designer = $this->alfa->user('designer');
        $visualizer = $this->alfa->user('visualizer');

        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(9, 0), 'ended_at' => now()->setTime(13, 0),
        ]));
        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(13, 0), 'ended_at' => now()->setTime(15, 0),
        ]));
        // Başqa işçinin eyni saatları — heç bir əlaqəsi yoxdur.
        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $visualizer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(10, 0), 'ended_at' => now()->setTime(12, 0),
        ]));

        $this->assertSame(3, $this->inTenant($this->alfa, fn () => TimeEntry::count()));
        $this->assertSame(
            360,
            (int) $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $designer->id)->sum('duration_minutes')),
        );
    }

    /** Yalnız `duration_minutes` ilə gələn qeydlərin vaxt oxu yoxdur — kəsişmə yoxlaması onlara toxunmur. */
    public function test_duration_only_entries_are_never_treated_as_overlapping(): void
    {
        $designer = $this->alfa->user('designer');

        $this->inTenant($this->alfa, function () use ($designer) {
            TimeEntry::create(['user_id' => $designer->id, 'project_id' => $this->alfa->project->id, 'duration_minutes' => 120]);
            TimeEntry::create(['user_id' => $designer->id, 'project_id' => $this->alfa->project->id, 'duration_minutes' => 60]);
        });

        $this->assertSame(180, (int) $this->inTenant($this->alfa, fn () => TimeEntry::sum('duration_minutes')));
    }

    /** Öz qeydini yenidən saxlamaq (`whereKeyNot`) özü ilə kəsişmə saymır; ancaq başqasının üstünə köçürmək olmaz. */
    public function test_editing_a_time_entry_does_not_clash_with_itself_but_clashes_with_others(): void
    {
        $designer = $this->alfa->user('designer');

        $first = $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(9, 0), 'ended_at' => now()->setTime(11, 0),
        ]));
        $second = $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $designer->id, 'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(14, 0), 'ended_at' => now()->setTime(16, 0),
        ]));

        // Öz-özünə toxunmur.
        $this->inTenant($this->alfa, fn () => $first->update(['comment' => 'Qeyd']));
        $this->assertSame(120, $first->fresh()->duration_minutes);

        // İkincini birincinin üstünə çəkmək bloklanır.
        $this->expectException(RuntimeException::class);
        $this->inTenant($this->alfa, fn () => $second->update([
            'started_at' => now()->setTime(10, 0), 'ended_at' => now()->setTime(12, 0),
        ]));
    }

    // =====================================================================
    // 3. [ORTA] Tərs interval
    // =====================================================================

    /** 18:00 → 17:55 intervalına yazılmış `duration_minutes = 600` olduğu kimi qalırdı. */
    public function test_reversed_interval_is_rejected_instead_of_keeping_the_typed_duration(): void
    {
        try {
            $this->inTenant($this->alfa, fn () => TimeEntry::create([
                'user_id' => $this->alfa->user('designer')->id,
                'project_id' => $this->alfa->project->id,
                'started_at' => now()->setTime(18, 0),
                'ended_at' => now()->setTime(17, 55),
                'duration_minutes' => 600,
            ]));
            $this->fail('Tərs interval saxlanıldı.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('başlanğıcdan sonra', $e->getMessage());
        }

        $this->assertSame(0, $this->inTenant($this->alfa, fn () => TimeEntry::count()));
    }

    /** Sıfır uzunluqlu interval da eyni yolla saxta müddət saxlayırdı. */
    public function test_zero_length_interval_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);

        $this->inTenant($this->alfa, fn () => TimeEntry::create([
            'user_id' => $this->alfa->user('designer')->id,
            'project_id' => $this->alfa->project->id,
            'started_at' => now()->setTime(12, 0),
            'ended_at' => now()->setTime(12, 0),
            'duration_minutes' => 480,
        ]));
    }

    // =====================================================================
    // 2. [CİDDİ] Başqasının adına saat yazmaq
    // =====================================================================

    /** Scope-lu rol üçün «İşçi» seçimi yalnız özüdür (UI qatı). */
    public function test_user_dropdown_is_limited_to_self_for_a_scoped_role(): void
    {
        $designer = $this->asStaff($this->alfa, 'designer');

        Livewire::test(CreateTimeEntry::class)
            ->assertFormFieldExists(
                'user_id',
                fn (Select $field): bool => array_keys($field->getOptions()) === [$designer->id],
            );
    }

    /**
     * Əsas sübut: UI gizlətməsindən ASILI OLMAYARAQ, hazırlanmış Livewire
     * state-i ilə də dizayner vizualizatorun adına saat yaza bilmir — nə
     * policy, nə forma validasiyası buraxır.
     */
    public function test_scoped_role_cannot_log_time_against_another_user(): void
    {
        $designer = $this->asStaff($this->alfa, 'designer');
        $victim = $this->alfa->user('visualizer');
        $victim->forceFill(['hourly_internal_cost' => 50])->save();

        // Policy qatı: «Yarat» düyməsi görünür, konkret yad işçi üçün isə yox.
        $this->assertTrue($designer->can('create', TimeEntry::class));
        $this->assertTrue($designer->can('create', [TimeEntry::class, $designer->id]));
        $this->assertFalse($designer->can('create', [TimeEntry::class, $victim->id]));

        // Validasiya qatı: müştəridən gələn state serverdə rədd olunur.
        Livewire::test(CreateTimeEntry::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'user_id' => $victim->id,
                'started_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ended_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'source' => 'manual',
            ])
            ->call('create')
            ->assertHasFormErrors(['user_id']);

        $this->assertNull(
            $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $victim->id)->first()),
            'Yad işçinin adına 400 AZN maya dəyəri yazıldı.',
        );

        // Öz adına yazmaq işləyir — düzəliş modulu bağlamır.
        Livewire::test(CreateTimeEntry::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'user_id' => $designer->id,
                'started_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ended_at' => now()->setTime(17, 0)->format('Y-m-d H:i:s'),
                'source' => 'manual',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $mine = $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $designer->id)->first());
        $this->assertNotNull($mine);
        // Yaradan öz qeydini görür və silə bilir — köhnə baqda bu mümkün deyildi.
        $this->assertTrue($designer->can('view', $mine));
        Livewire::test(ListTimeEntries::class)->assertCanSeeTableRecords([$mine]);
    }

    /** Sahibkar (matrisdə «yalnız öz layihələri» qeydi yoxdur) komandanın adına yaza bilir. */
    public function test_owner_may_still_log_time_for_another_employee(): void
    {
        $owner = $this->asStaff($this->alfa, 'owner');
        $visualizer = $this->alfa->user('visualizer');

        $this->assertTrue($owner->can('create', [TimeEntry::class, $visualizer->id]));

        Livewire::test(CreateTimeEntry::class)
            ->fillForm([
                'project_id' => $this->alfa->project->id,
                'user_id' => $visualizer->id,
                'started_at' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'ended_at' => now()->setTime(12, 0)->format('Y-m-d H:i:s'),
                'source' => 'manual',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(
            180,
            $this->inTenant($this->alfa, fn () => TimeEntry::where('user_id', $visualizer->id)->value('duration_minutes')),
        );
    }

    // =====================================================================
    // 4. [CİDDİ] Lump-sum (layihəsiz) satınalma sifarişi
    // =====================================================================

    /**
     * `can('view')`/`can('update')` true, siyahı isə boş idi: `whereHas('project')`
     * NULL layihəni kənarlaşdırırdı. İndi policy ilə siyahı razılaşır.
     */
    public function test_lump_sum_purchase_order_is_visible_to_the_procurement_role(): void
    {
        $lump = $this->inTenant($this->alfa, function () {
            $supplier = Supplier::create(['name' => 'Alfa təchizat']);

            return PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => null,
                'order_date' => now()->toDateString(),
                'total' => 5000,
                'status' => 'draft',
            ]);
        });

        $assigned = $this->inTenant($this->alfa, function () {
            $supplier = Supplier::create(['name' => 'Alfa təchizat 2']);

            return PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => $this->alfa->project->id,
                'order_date' => now()->toDateString(),
                'total' => 100,
                'status' => 'draft',
            ]);
        });

        // Komplektləşdirici alfa-nın əsas layihəsinin üzvüdür, ikinci layihənin yox.
        $foreign = $this->inTenant($this->alfa, function () {
            $supplier = Supplier::create(['name' => 'Alfa təchizat 3']);

            return PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => $this->alfa->otherProject->id,
                'order_date' => now()->toDateString(),
                'total' => 100,
                'status' => 'draft',
            ]);
        });

        $procurement = $this->asStaff($this->alfa, 'procurement');

        $this->assertTrue($procurement->can('view', $lump));
        $this->assertTrue($procurement->can('update', $lump));
        $this->assertFalse($procurement->can('view', $foreign));

        Livewire::test(ListPurchaseOrders::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$lump, $assigned])
            // Düzəliş scope-u açmır: yad layihənin sifarişi yenə görünmür.
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    /** Lump-sum sifariş STUDIYA sərhədini keçmir — `orWhereNull` tenant scope-una toxunmur. */
    public function test_lump_sum_purchase_order_does_not_cross_studios(): void
    {
        $betaLump = $this->inTenant($this->beta, function () {
            $supplier = Supplier::create(['name' => 'Beta təchizat']);

            return PurchaseOrder::create([
                'supplier_id' => $supplier->id,
                'project_id' => null,
                'order_date' => now()->toDateString(),
                'total' => 7000,
                'status' => 'draft',
            ]);
        });

        $this->asStaff($this->alfa, 'procurement');

        Livewire::test(ListPurchaseOrders::class)
            ->assertOk()
            ->assertCanNotSeeTableRecords([$betaLump]);
    }

    // =====================================================================
    // 5. [BLOKER] Bir studiyanın açarı o birinin bildirişlərini söndürürdü
    // =====================================================================

    /** Modelin `$fillable`-ı `tenant_id`-ni artıq atmır. */
    public function test_tenant_id_is_mass_assignable_on_automation_rule(): void
    {
        $this->assertContains('tenant_id', (new AutomationRule)->getFillable());

        $created = AutomationRule::create([
            'tenant_id' => $this->beta->tenant->id,
            'code' => 'rule-probe', 'name' => 'Test', 'trigger' => 't',
            'priority' => 'medium', 'enabled' => false,
        ]);

        $this->assertSame($this->beta->tenant->id, $created->fresh()->tenant_id);
    }

    /**
     * Panelin ƏSL ToggleColumn yolu: beta-nın sahibkarı platforma qaydasını
     * söndürür. Əvvəl bu, `tenant_id = NULL` olan İKİNCİ platforma sətri
     * yaradırdı və `isEnabled()` sonuncu sətri tətbiq etdiyi üçün ALFA-da da
     * sönürdü. İndi beta üçün override yaranır, alfa toxunulmaz qalır.
     */
    public function test_one_studios_toggle_no_longer_silences_the_other_studio(): void
    {
        $platform = new AutomationRule;
        $platform->forceFill([
            'tenant_id' => null, 'code' => 'rule-9', 'name' => 'Gecikmiş tapşırıq',
            'trigger' => 'task.overdue', 'priority' => 'medium', 'enabled' => true,
        ])->save();

        $this->asStaff($this->beta, 'owner');

        Livewire::test(ListAutomationRules::class)
            ->assertOk()
            ->call('updateTableColumnState', 'enabled', (string) $platform->getKey(), false);

        // 1) Override sətri STUDİYAYA yazılıb, ikinci platforma sətri YARANMAYIB.
        $this->assertSame(
            1,
            AutomationRule::query()->whereNull('tenant_id')->where('code', 'rule-9')->count(),
            'Platforma səviyyəsində dublikat sətir yarandı.',
        );
        $this->assertSame(
            1,
            AutomationRule::query()->where('tenant_id', $this->beta->tenant->id)->where('code', 'rule-9')->count(),
        );
        $this->assertTrue(
            (bool) AutomationRule::query()->whereNull('tenant_id')->where('code', 'rule-9')->value('enabled'),
            'Platforma sətri beta-nın qərarı ilə dəyişdi.',
        );

        // 2) Nəticə: hər studiya öz cavabını alır.
        $ctx = app(TenantContext::class);

        $this->assertTrue(
            $ctx->actingAs($this->alfa->tenant->id, fn () => (new AutomationEngine)->isEnabled('rule-9')),
            'Alfa-nın qaydası beta-nın açarı ilə söndü.',
        );
        $this->assertFalse(
            $ctx->actingAs($this->beta->tenant->id, fn () => (new AutomationEngine)->isEnabled('rule-9')),
            'Beta-nın öz override-i tətbiq olunmadı.',
        );
    }

    /** Baqın QOYDUĞU zədə: eyni `code` üçün bir neçə NULL sətir — miqrasiya sonuncunu saxlayır. */
    public function test_dedupe_migration_keeps_only_the_last_platform_row(): void
    {
        foreach ([true, false, true] as $enabled) {
            (new AutomationRule)->forceFill([
                'tenant_id' => null, 'code' => 'rule-9', 'name' => 'Gecikmiş tapşırıq',
                'trigger' => 'task.overdue', 'priority' => 'medium', 'enabled' => $enabled,
            ])->save();
        }

        // Toxunulmamalı sətirlər: başqa kod və studiya override-i.
        (new AutomationRule)->forceFill([
            'tenant_id' => null, 'code' => 'rule-16', 'name' => 'Razılaşdırma',
            'trigger' => 't', 'priority' => 'medium', 'enabled' => true,
        ])->save();
        (new AutomationRule)->forceFill([
            'tenant_id' => $this->beta->tenant->id, 'code' => 'rule-9', 'name' => 'Gecikmiş tapşırıq',
            'trigger' => 'task.overdue', 'priority' => 'medium', 'enabled' => false,
        ])->save();

        $lastId = AutomationRule::query()->whereNull('tenant_id')->where('code', 'rule-9')->max('id');

        require_once database_path('migrations/2026_09_27_020000_dedupe_platform_automation_rules.php');
        (include database_path('migrations/2026_09_27_020000_dedupe_platform_automation_rules.php'))->up();

        $remaining = AutomationRule::query()->whereNull('tenant_id')->where('code', 'rule-9')->pluck('id');
        $this->assertCount(1, $remaining);
        $this->assertSame($lastId, $remaining->first(), 'Ən sonuncu platforma sətri saxlanmalıdır.');

        $this->assertSame(1, AutomationRule::query()->whereNull('tenant_id')->where('code', 'rule-16')->count());
        $this->assertSame(1, AutomationRule::query()->where('tenant_id', $this->beta->tenant->id)->count());
    }

    // =====================================================================
    // 6. [ORTA] `tasks:notify-deadlines` studiyanın override-ini oxumur
    // =====================================================================

    /**
     * Beta rule-9-u söndürür → beta-nın işçisinə gecikmə bildirişi GETMİR,
     * alfa-nın işçisi isə bildirişi alır. Əvvəl əmr CLI-da tenant konteksti
     * qurmadığı üçün yalnız platforma sətirlərini oxuyurdu və beta-ya da
     * bildiriş gedirdi.
     */
    public function test_deadline_command_honours_each_studios_own_automation_override(): void
    {
        $ctx = app(TenantContext::class);

        (new AutomationRule)->forceFill([
            'tenant_id' => null, 'code' => 'rule-9', 'name' => 'Gecikmiş tapşırıq',
            'trigger' => 'task.overdue', 'priority' => 'medium', 'enabled' => true,
        ])->save();

        (new AutomationRule)->forceFill([
            'tenant_id' => $this->beta->tenant->id, 'code' => 'rule-9',
            'name' => 'Gecikmiş tapşırıq', 'trigger' => 'task.overdue',
            'priority' => 'medium', 'enabled' => false,
        ])->save();

        foreach ([$this->alfa, $this->beta] as $world) {
            $ctx->actingAs($world->tenant->id, fn () => Task::query()
                ->whereKey($world->task->id)
                ->update(['deadline' => now()->subDay()->toDateString()]));
        }

        Notification::fake();

        $this->artisan('tasks:notify-deadlines')->assertSuccessful();

        Notification::assertSentTo($this->alfa->user('designer'), TaskOverdue::class);
        Notification::assertNotSentTo(
            $this->beta->user('designer'),
            TaskOverdue::class,
        );
    }

    /** Söndürülmüş platforma qaydası hər iki studiyada susur — scope düzəlişi qapını açmır. */
    public function test_deadline_command_stays_silent_when_the_platform_rule_is_off(): void
    {
        $ctx = app(TenantContext::class);

        (new AutomationRule)->forceFill([
            'tenant_id' => null, 'code' => 'rule-9', 'name' => 'Gecikmiş tapşırıq',
            'trigger' => 'task.overdue', 'priority' => 'medium', 'enabled' => false,
        ])->save();

        foreach ([$this->alfa, $this->beta] as $world) {
            $ctx->actingAs($world->tenant->id, fn () => Task::query()
                ->whereKey($world->task->id)
                ->update(['deadline' => now()->subDay()->toDateString()]));
        }

        Notification::fake();

        $this->artisan('tasks:notify-deadlines')->assertSuccessful();

        Notification::assertNothingSentTo($this->alfa->user('designer'));
        Notification::assertNothingSentTo($this->beta->user('designer'));
    }
}
