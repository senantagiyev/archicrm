<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_landing_page_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('Dizayn bürosu üçün idarəetmə sistemi');
    }

    /**
     * Giriş səhifəsi yalnız sifarişçi üçündür: büro komandasının girişi
     * qəsdən göstərilmir — panel öz gizli ünvanından açılır.
     */
    public function test_the_entry_page_offers_only_the_customer_login(): void
    {
        $this->get('/giris')
            ->assertOk()
            ->assertSee('Portala daxil ol')
            ->assertSee(route('portal.login'), false)
            ->assertDontSee('Büro komandası')
            ->assertDontSee(route('filament.app.auth.login'), false);
    }

    public function test_the_admin_panel_lives_on_the_hidden_path(): void
    {
        $this->get('/idaresistem229/login')->assertOk();
        $this->get('/app')->assertNotFound();
    }
}
