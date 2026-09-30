<?php

namespace Tests\Feature;

use App\Filament\Resources\ClientResource\Pages\CreateClient;
use App\Filament\Resources\ClientResource\Pages\EditClient;
use App\Filament\Resources\ClientResource\RelationManagers\ClientUsersRelationManager;
use App\Models\Client;
use App\Models\ClientUser;
use App\Support\TenantContext;
use Database\Seeders\TranslationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Müştərinin ŞİFRƏ ilə girişi — birdəfəlik linkə alternativ.
 *
 * İki tərəf yoxlanılır: studiya paneldən şifrəni necə verir (müştəri
 * formasında və portal hesabları cədvəlində), müştəri portalda onunla necə
 * girir — və nə vaxt GİRƏ BİLMƏMƏLİDİR (yanlış şifrə, şifrəsiz hesab, ləğv
 * edilmiş hesab, arxivlənmiş müştəri, hədd).
 */
class PortalPasswordLoginTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(TranslationSeeder::class);
        $this->studio = StudioWorld::make('pwlogin');
        RateLimiter::clear('auth');
    }

    // ──────────────────────────── Panel tərəfi ────────────────────────────

    /** Müştəri yaradılarkən e-poçt + şifrə verildikdə portal hesabı dərhal yaranır. */
    public function test_creating_a_client_with_email_and_password_creates_a_portal_account(): void
    {
        $this->asOwner();

        Livewire::test(CreateClient::class)
            ->fillForm([
                'name' => 'Aysel Məmmədova',
                'status' => 'client',
                'email' => 'aysel@example.test',
                'portal_password' => 'Gizli-Sifre-123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::where('email', 'aysel@example.test')->firstOrFail();
        $account = ClientUser::where('client_id', $client->id)->firstOrFail();

        $this->assertSame('aysel@example.test', $account->email);
        $this->assertSame('Aysel Məmmədova', $account->name);
        $this->assertTrue(Hash::check('Gizli-Sifre-123', $account->password), 'Şifrə heşlənmiş saxlanmalıdır.');
        $this->assertSame($this->studio->tenant->id, $account->tenant_id, 'Portal hesabı studiyaya bağlanmadı.');
    }

    /** Şifrə boş qalırsa heç bir portal hesabı yaranmır — link axını dəyişmir. */
    public function test_creating_a_client_without_a_password_creates_no_portal_account(): void
    {
        $this->asOwner();

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Linkli müştəri', 'status' => 'client', 'email' => 'link@example.test'])
            ->call('create')
            ->assertHasNoFormErrors();

        $client = Client::where('email', 'link@example.test')->firstOrFail();

        $this->assertSame(0, ClientUser::where('client_id', $client->id)->count());
    }

    /** Şifrə var, e-poçt yox — forma bunu qəbul etməməlidir. */
    public function test_a_password_without_an_email_is_refused_by_the_form(): void
    {
        $this->asOwner();

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'E-poçtsuz', 'status' => 'client', 'portal_password' => 'Gizli-Sifre-123'])
            ->call('create')
            ->assertHasFormErrors(['portal_password']);

        $this->assertNull(Client::where('name', 'E-poçtsuz')->first());
    }

    /** Qısa şifrə qəbul edilmir. */
    public function test_a_short_password_is_refused(): void
    {
        $this->asOwner();

        Livewire::test(CreateClient::class)
            ->fillForm(['name' => 'Qısa', 'status' => 'client', 'email' => 'qisa@example.test', 'portal_password' => '1234567'])
            ->call('create')
            ->assertHasFormErrors(['portal_password']);
    }

    /** Mövcud müştərini redaktə edərkən şifrə yazılsa, mövcud hesabın şifrəsi dəyişir. */
    public function test_editing_a_client_sets_the_password_on_the_existing_account(): void
    {
        $this->asOwner();

        $client = $this->studio->client;
        $client->forceFill(['email' => $this->studio->portalUser->email])->save();

        $this->assertTrue(blank($this->studio->portalUser->password), 'Başlanğıcda hesab şifrəsizdir — testin şərti.');

        Livewire::test(EditClient::class, ['record' => $client->getKey()])
            ->fillForm(['portal_password' => 'Yeni-Sifre-456'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, ClientUser::where('client_id', $client->id)->count(), 'İkinci hesab yaranmamalı idi.');
        $this->assertTrue(Hash::check('Yeni-Sifre-456', $this->studio->portalUser->fresh()->password));
    }

    /** Portal hesabları cədvəlindən şifrə təyin etmək və dəyişmək. */
    public function test_the_portal_accounts_table_can_set_and_change_a_password(): void
    {
        $this->asOwner();

        $component = Livewire::test(ClientUsersRelationManager::class, [
            'ownerRecord' => $this->studio->client,
            'pageClass' => EditClient::class,
        ]);

        $component
            ->callTableAction('setPassword', $this->studio->portalUser, data: ['password' => 'Cedvel-Sifre-789'])
            ->assertHasNoTableActionErrors();

        $this->assertTrue(Hash::check('Cedvel-Sifre-789', $this->studio->portalUser->fresh()->password));

        // Giriş üsulu sütunu vəziyyəti göstərir.
        $this->assertStringContainsString('Şifrə + link', $component->html());

        // Yeni hesab birbaşa şifrə ilə.
        $component
            ->callTableAction('createWithPassword', data: [
                'name' => 'İkinci əlaqə',
                'email' => 'ikinci@example.test',
                'password' => 'Ikinci-Sifre-000',
            ])
            ->assertHasNoTableActionErrors();

        $second = ClientUser::where('email', 'ikinci@example.test')->firstOrFail();
        $this->assertSame($this->studio->client->id, $second->client_id);
        $this->assertTrue(Hash::check('Ikinci-Sifre-000', $second->password));
    }

    // ──────────────────────────── Portal tərəfi ────────────────────────────

    /** Giriş səhifəsi hər iki yolu təklif edir, büro girişini isə YOX. */
    public function test_the_login_page_offers_password_and_link_but_not_the_staff_login(): void
    {
        $response = $this->get(route('portal.login'));

        $response->assertOk()
            ->assertSee(t('portal.login_password_tab'))
            ->assertSee(t('portal.login_link_tab'))
            ->assertSee(route('portal.login.password'), false)
            ->assertSee(route('portal.login-link'), false)
            ->assertSee('name="password"', false)
            ->assertDontSee(route('filament.app.auth.login'), false)
            ->assertDontSee('Büro komandası');
    }

    /** Düzgün e-poçt + şifrə → giriş, son giriş tarixi, portala yönləndirmə. */
    public function test_a_client_can_log_in_with_email_and_password(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        $response = $this->post(route('portal.login.password'), [
            'email' => $user->email,
            'password' => 'Gizli-Sifre-123',
        ]);

        $response->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($user, 'customer');
        $this->assertNotNull($user->fresh()->last_login_at);

        // Sessiya həqiqətən işləyir — qorunan səhifə açılır. Tək layihəli
        // müştəri ana səhifədən birbaşa layihəsinə yönləndirilir.
        $this->get(route('portal.home'))
            ->assertRedirect(route('portal.projects.show', $this->studio->project));
        $this->get(route('portal.projects.show', $this->studio->project))->assertOk();
    }

    /** «Məni xatırla» remember-token yazır. */
    public function test_remember_me_issues_a_remember_token(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        $this->post(route('portal.login.password'), [
            'email' => $user->email,
            'password' => 'Gizli-Sifre-123',
            'remember' => '1',
        ])->assertRedirect(route('portal.home'));

        $this->assertNotEmpty($user->fresh()->getRememberToken());
    }

    /** Yanlış şifrə → giriş yoxdur, eyni ümumi mesaj. */
    public function test_a_wrong_password_is_refused(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        $this->from(route('portal.login'))
            ->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'yanlis-sifre'])
            ->assertRedirect(route('portal.login'))
            ->assertSessionHasErrors(['email' => t('portal.login_failed')]);

        $this->assertGuest('customer');
    }

    /**
     * Şifrəsiz («yalnız link») hesab şifrə ilə girə bilməz — və cavab yanlış
     * şifrə ilə EYNİDİR ki, hansı e-poçtun mövcud olduğu sızmasın.
     */
    public function test_a_link_only_account_cannot_log_in_with_any_password(): void
    {
        $user = $this->studio->portalUser;
        $this->assertTrue(blank($user->password));

        $this->from(route('portal.login'))
            ->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'her-hansi-sifre'])
            ->assertSessionHasErrors(['email' => t('portal.login_failed')]);

        $this->assertGuest('customer');
    }

    /** Mövcud olmayan e-poçt da eyni mesajı alır. */
    public function test_an_unknown_email_gets_the_same_generic_error(): void
    {
        $this->from(route('portal.login'))
            ->post(route('portal.login.password'), ['email' => 'yox@example.test', 'password' => 'Gizli-Sifre-123'])
            ->assertSessionHasErrors(['email' => t('portal.login_failed')]);

        $this->assertGuest('customer');
    }

    /** Girişi ləğv edilmiş (soft-delete) hesab şifrəsi düz olsa da girə bilməz. */
    public function test_a_revoked_account_cannot_log_in(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');
        $user->delete();

        $this->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'Gizli-Sifre-123'])
            ->assertSessionHasErrors(['email' => t('portal.login_failed')]);

        $this->assertGuest('customer');
    }

    /**
     * Müştəri silinibsə, amma portal hesabı nədənsə diri qalıbsa (adi silmədə
     * `Client::deleted` hesabları da bağlayır — burada o hook ötürülür ki,
     * məhz bu ehtiyat sədd yoxlansın), giriş açıq izahla dayandırılır.
     */
    public function test_an_account_of_an_archived_client_cannot_log_in(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        $this->studio->client->projects()->get()->each->delete();
        $this->studio->client->deleteQuietly();

        $this->assertNull($user->fresh()->deleted_at, 'Testin şərti: hesab diri, müştəri silinib.');

        $this->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'Gizli-Sifre-123'])
            ->assertSessionHasErrors(['email' => t('portal.login_client_inactive')]);

        $this->assertGuest('customer');
    }

    /** Şifrə tapma cəhdləri link endpointi ilə eyni sərt həddə dayanır. */
    public function test_password_login_is_rate_limited(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'yanlis-'.$i])
                ->assertStatus(302);
        }

        $this->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'yanlis-6'])
            ->assertStatus(429);
    }

    /** reCAPTCHA açıqdırsa token olmadan giriş keçmir. */
    public function test_password_login_requires_recaptcha_when_enabled(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        config(['services.recaptcha.site_key' => 'site', 'services.recaptcha.secret_key' => 'secret']);

        $this->from(route('portal.login'))
            ->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'Gizli-Sifre-123'])
            ->assertRedirect(route('portal.login'))
            ->assertSessionHasErrors(['g-recaptcha-response']);

        $this->assertGuest('customer');
    }

    /** Şifrə təyin edilməsi link ilə girişi BAĞLAMIR — hər ikisi işləyir. */
    public function test_the_magic_link_still_works_for_an_account_with_a_password(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        $plain = 'test-token-'.str_repeat('a', 30);
        $user->forceFill(['magic_token' => hash('sha256', $plain)])->save();

        $url = URL::temporarySignedRoute(
            'portal.magic-login',
            now()->addHour(),
            ['clientUser' => $user->id, 't' => $plain],
        );

        $this->get($url)->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($user, 'customer');
    }

    /** Portal sessiyası paneli açmır. */
    public function test_a_password_session_grants_no_admin_panel_access(): void
    {
        $user = $this->withPassword('Gizli-Sifre-123');

        $this->post(route('portal.login.password'), ['email' => $user->email, 'password' => 'Gizli-Sifre-123']);

        $status = $this->get(route('filament.app.pages.dashboard'))->status();

        $this->assertContains($status, [302, 403], 'Müştəri sessiyası panelə girdi.');
    }

    // ──────────────────────────── Köməkçilər ────────────────────────────

    private function asOwner(): void
    {
        $this->actingAs($this->studio->user('owner'));
        app(TenantContext::class)->set($this->studio->tenant->id);
    }

    private function withPassword(string $password): ClientUser
    {
        $user = $this->studio->portalUser;
        $user->forceFill(['password' => $password])->save();

        return $user->fresh();
    }
}
