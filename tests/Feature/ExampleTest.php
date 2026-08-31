<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_the_landing_page_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('ARCHI CRM');
    }

    public function test_the_entry_page_offers_both_logins(): void
    {
        $this->get('/giris')
            ->assertOk()
            ->assertSee('Büro komandası')
            ->assertSee('Sifarişçi');
    }

    public function test_the_admin_panel_lives_on_the_hidden_path(): void
    {
        $this->get('/idaresistem229/login')->assertOk();
        $this->get('/app')->assertNotFound();
    }
}
