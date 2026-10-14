<?php

namespace Tests\Feature;

use App\Enums\Domain;
use App\Exceptions\RowVersionConflictException;
use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\Role;
use App\Models\User;
use App\Support\AccessMatrix;
use App\Support\Errors\ExceptionMessageResolver;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use PDOException;
use RuntimeException;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Admin panelində əməliyyat xətası: ümumi «Error» pəncərəsi əvəzinə səbəbi
 * və nə etmək lazım olduğunu deyən qırmızı bildiriş (App\Support\Errors).
 *
 * Prod-da APP_DEBUG=false olduğu üçün testlər də debug-u söndürür — admin-in
 * real gördüyü mətn yoxlanılır (texniki məlumat sətri yalnız debug-da çıxır).
 */
class AdminFriendlyErrorsTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private Role $role;

    private ?string $logPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);

        $this->studio = StudioWorld::make('friendlyerr');

        $this->role = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => Role::create([
            'tenant_id' => $this->studio->tenant->id,
            'key' => 'friendly_smetaci',
            'name' => 'Smetaçı',
            // Formada hər domenin səviyyəsi məcburidir — tam matris verilir ki,
            // «Yadda saxla» validasiyadan keçib modelə çatsın.
            'levels' => collect(Domain::cases())->mapWithKeys(fn (Domain $d) => [$d->value => 0])->put('projects', 1)->all(),
            'own_projects_only' => false,
            'is_system' => false,
            'active' => true,
        ]));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        if ($this->logPath !== null && is_file($this->logPath)) {
            @unlink($this->logPath);
        }

        parent::tearDown();
    }

    // ───────────────────── 1. Domen xətası → real mesaj ─────────────────────

    public function test_a_domain_rule_violation_is_shown_as_a_danger_notification_with_its_real_message(): void
    {
        $this->asStaff('owner');
        $this->studio->user('designer')->update(['role_id' => $this->role->id]);

        // Role::deleting işçisi olan rolu silməyə qoymur və səbəbini yazır.
        // Əvvəl bu mətn admin-ə çatmırdı — brauzer yalnız «Error» göstərirdi.
        Livewire::test(EditRole::class, ['record' => $this->role->getKey()])
            ->callAction('delete');
        $this->assertNotificationTitle('Əməliyyat yerinə yetirilmədi');

        $notification = $this->lastNotification();
        $this->assertSame('danger', $notification['status']);
        $this->assertSame('persistent', $notification['duration'], 'Bildiriş özü-özünə tez itməməlidir.');
        $this->assertStringContainsString('Bu rol 1 işçiyə təyin olunub. Əvvəlcə onlara başqa rol verin, sonra silin.', $notification['body']);
        $this->assertStringNotContainsString('ERR-', $notification['body'], 'Admin-in özü həll edə biləcəyi halda xəta kodu lazım deyil.');

        $this->assertTrue(Role::query()->whereKey($this->role->id)->exists(), 'Rol silinməməli idi.');
    }

    public function test_a_row_version_conflict_gets_the_reload_explanation(): void
    {
        $this->asStaff('owner');
        Role::updating(fn () => throw new RowVersionConflictException(currentVersion: 3, yourVersion: 2));

        $this->editRoleName('Yeni ad');
        $this->assertNotificationTitle('Konflikt: qeyd yeniləndi');

        $this->assertStringContainsString('başqası tərəfindən dəyişdirilib', $this->lastNotification()['body']);
        $this->assertStringContainsString('səhifəni yeniləyib yenidən cəhd edin', $this->lastNotification()['body']);
    }

    public function test_an_authorization_failure_names_the_section(): void
    {
        $this->asStaff('owner');
        Role::updating(fn () => throw new AuthorizationException);

        $this->editRoleName('Yeni ad');
        $this->assertNotificationTitle('Bu əməliyyata icazəniz yoxdur');

        $body = $this->lastNotification()['body'];
        $this->assertStringContainsString('«'.RoleResource::getNavigationLabel().'» bölməsində', $body);
        $this->assertStringNotContainsString('This action is unauthorized', $body);
    }

    public function test_a_missing_record_asks_to_reload_the_page(): void
    {
        $this->asStaff('owner');
        Role::updating(fn () => throw (new ModelNotFoundException)->setModel(Role::class, [999]));

        $this->editRoleName('Yeni ad');
        $this->assertNotificationTitle('Qeyd tapılmadı');

        $body = $this->lastNotification()['body'];
        $this->assertStringContainsString('silinmiş və ya başqa istifadəçi tərəfindən dəyişdirilmiş ola bilər', $body);
        $this->assertStringContainsString('Səhifəni yeniləyin', $body);
        $this->assertStringNotContainsString('App\\Models', $body, 'Model sinfinin adı UI-a sızmamalıdır.');
    }

    // ───────────────────── 2. Validasiya əvvəlki kimi işləyir ─────────────────────

    public function test_validation_errors_still_render_on_the_fields(): void
    {
        $this->asStaff('owner');

        $this->editRoleName('')->assertHasFormErrors(['name' => 'required']);

        $this->assertSame([], $this->dangerNotifications(), 'Validasiya xətası bildirişə çevrilməməlidir.');
    }

    // ───────────────────── 3. Gözlənilməz xəta → kod + jurnal ─────────────────────

    public function test_an_unexpected_error_shows_a_generic_message_with_a_reference_code_that_is_logged(): void
    {
        $this->useTemporaryLog();
        $this->asStaff('owner');

        // Vendor/test kodundan atılan xəta — mesajı istifadəçi üçün yazılmayıb.
        Role::updating(fn () => throw new \LogicException('SQLSTATE gizli: select * from secret_table where password = 123'));

        $this->editRoleName('Yeni ad');
        $this->assertNotificationTitle(ExceptionMessageResolver::GENERIC_TITLE);

        $body = $this->lastNotification()['body'];
        $this->assertStringContainsString(ExceptionMessageResolver::GENERIC_MESSAGE, $body);
        $this->assertMatchesRegularExpression('/ERR-[0-9A-F]{6}/', $body);
        $this->assertStringNotContainsString('secret_table', $body);
        $this->assertStringNotContainsString('password', $body);

        preg_match('/ERR-[0-9A-F]{6}/', $body, $match);
        $log = (string) file_get_contents($this->logPath);
        $this->assertStringContainsString($match[0], $log, 'Xəta kodu jurnalda tapılmalıdır.');
        $this->assertStringContainsString('secret_table', $log, 'Orijinal xəta jurnalda tam saxlanmalıdır.');

        $this->assertSame('Smetaçı', $this->role->fresh()->name, 'Uğursuz yadda saxlama heç nə yazmamalı idi.');
    }

    public function test_a_database_error_is_explained_without_leaking_sql(): void
    {
        $this->useTemporaryLog();
        $this->asStaff('owner');

        $roleId = $this->role->id;

        // Real UNIQUE pozuntusu: eyni id ilə ikinci sətir.
        Role::updating(function () use ($roleId): void {
            DB::table('roles')->insert((array) DB::table('roles')->where('id', $roleId)->first());
        });

        $this->editRoleName('Yeni ad');
        $this->assertNotificationTitle('Belə qeyd artıq mövcuddur');

        $body = $this->lastNotification()['body'];
        $this->assertMatchesRegularExpression('/ERR-[0-9A-F]{6}/', $body);

        foreach (['insert', 'roles', 'SQLSTATE', 'UNIQUE'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, strip_tags(str_replace('Xəta kodu', '', $body)), "UI-a «{$leak}» sızdı.");
        }

        $this->assertSame('Smetaçı', $this->role->fresh()->name);
    }

    /** SQLSTATE / sürücü kodları üzrə izahlar — MySQL, PostgreSQL və SQLite. */
    public function test_common_database_failures_map_to_plain_explanations(): void
    {
        $resolver = app(ExceptionMessageResolver::class);

        $cases = [
            ['delete from `clients` where `id` = ?', ['23000', 1451, 'Cannot delete or update a parent row: a foreign key constraint fails'], 'Qeyd silinə bilməz'],
            ['delete from "clients" where "id" = ?', ['23000', 19, 'FOREIGN KEY constraint failed'], 'Qeyd silinə bilməz'],
            ['insert into "tasks" ("stage_id") values (?)', ['23503', 7, 'insert or update on table "tasks" violates foreign key constraint'], 'Əlaqəli qeyd tapılmadı'],
            ['insert into "tasks" ("title") values (?)', ['23000', 19, 'NOT NULL constraint failed: tasks.title'], 'Məcburi sahə boşdur'],
            ['update `projects` set `name` = ?', ['22001', 1406, "Data too long for column 'name' at row 1"], 'Sahənin dəyəri çox uzundur'],
            ['update "invoices" set "total" = ?', ['22003', 7, 'numeric field overflow'], 'Rəqəm icazə verilən həddən kənardır'],
            ['insert into `clients` (`email`) values (?)', ['23000', 1062, "Duplicate entry 'a@b.az' for key 'clients_email_unique'"], 'Belə qeyd artıq mövcuddur'],
            ['update "projects" set "name" = ?', ['HY000', 5, 'database is locked'], 'Məlumat bazası məşğuldur'],
            ['select * from "projects"', ['HY000', 1, 'no such column: secret'], 'Məlumat bazası xətası'],
        ];

        foreach ($cases as [$sql, $errorInfo, $title]) {
            $pdo = new PDOException('SQLSTATE['.$errorInfo[0].']: '.$errorInfo[2]);
            $pdo->errorInfo = $errorInfo;

            $error = $resolver->resolve(new QueryException('test', $sql, ['gizli@poct.az'], $pdo));

            $this->assertSame($title, $error->title, $sql);
            $this->assertFalse($error->expected, 'Baza xətasında admin-ə xəta kodu göstərilməlidir.');

            $text = $error->message.' '.$error->hint;
            foreach ([$errorInfo[2], 'gizli@poct.az', 'SQLSTATE', 'select', 'insert'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $text, $sql);
            }
        }
    }

    /** Standart sinifli xəta yalnız bizim app/ kodumuz atıbsa mesajı göstərilir. */
    public function test_only_messages_written_by_our_own_code_are_shown_verbatim(): void
    {
        $resolver = app(ExceptionMessageResolver::class);

        // Vendor (burada test faylı) atdığı RuntimeException — texniki mətndir.
        $this->assertSame(
            ExceptionMessageResolver::GENERIC_TITLE,
            $resolver->resolve(new RuntimeException('A facade root has not been set.'))->title,
        );

        // Modelimizin atdığı qayda xətası — mesaj olduğu kimi.
        $this->studio->user('designer')->update(['role_id' => $this->role->id]);

        try {
            app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => $this->role->delete());
            $this->fail('Rol silinməməli idi.');
        } catch (RuntimeException $e) {
            $error = $resolver->resolve($e);
            $this->assertSame('Əməliyyat yerinə yetirilmədi', $error->title);
            $this->assertSame($e->getMessage(), $error->message);
            $this->assertTrue($error->expected);
        }
    }

    // ───────────────────── 4. İlk açılış (mount) toxunulmur ─────────────────────

    public function test_an_error_during_the_initial_mount_is_not_swallowed(): void
    {
        $this->asStaff('owner');
        $roleId = $this->role->id;

        Role::retrieved(function (Role $role) use ($roleId): void {
            if ($role->id === $roleId) {
                throw new RuntimeException('mount zamanı xəta');
            }
        });

        try {
            Livewire::test(EditRole::class, ['record' => $roleId]);
            $this->fail('Mount xətası udulmamalı idi.');
        } catch (\Throwable $e) {
            // Blade render-i xətanı ViewException-a büküb ötürür — əsas odur ki,
            // xəta YUXARI çıxır, bildirişə çevrilmir.
            $this->assertStringContainsString('mount zamanı xəta', $e->getMessage());
        }

        $this->assertSame([], $this->dangerNotifications());
    }

    // ─────────────────────────────── köməkçilər ───────────────────────────────

    private function editRoleName(string $name): mixed
    {
        return Livewire::test(EditRole::class, ['record' => $this->role->getKey()])
            ->fillForm(['name' => $name])
            ->call('save');
    }

    /**
     * Sessiyadakı bildirişlər. Filament update sorğusunun sonunda onları
     * `claimed_notifications`-a köçürür; `assertNotified()` isə oxuyarkən
     * sessiyadan SİLİR — ona görə gövdəni yoxlamaq üçün birbaşa oxuyuruq.
     *
     * @return list<array<string, mixed>>
     */
    private function notifications(): array
    {
        return array_values([
            ...session()->get('filament.claimed_notifications', []),
            ...session()->get('filament.notifications', []),
        ]);
    }

    /** @return array<string, mixed> */
    private function lastNotification(): array
    {
        $notifications = $this->notifications();
        $this->assertNotEmpty($notifications, 'Bildiriş göndərilməyib.');

        return end($notifications);
    }

    private function assertNotificationTitle(string $title): void
    {
        $this->assertSame($title, $this->lastNotification()['title']);
    }

    /** @return list<array<string, mixed>> */
    private function dangerNotifications(): array
    {
        return array_values(array_filter(
            $this->notifications(),
            fn (array $n): bool => ($n['status'] ?? null) === 'danger',
        ));
    }

    private function useTemporaryLog(): void
    {
        $this->logPath = storage_path('logs/admin-friendly-errors-'.uniqid().'.log');

        config([
            'logging.channels.admin_friendly_errors_test' => ['driver' => 'single', 'path' => $this->logPath, 'level' => 'debug'],
            'logging.default' => 'admin_friendly_errors_test',
        ]);
    }

    private function asStaff(string $role): User
    {
        $user = $this->studio->user($role);

        $this->flushSession();
        while (SupportRedirects::$redirectorCacheStack !== []) {
            app()->instance('redirect', array_pop(SupportRedirects::$redirectorCacheStack));
        }

        AccessMatrix::flushCache();
        $this->actingAs($user);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($this->studio->tenant->id);

        return $user;
    }
}
