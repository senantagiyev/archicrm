<?php

namespace Tests\Feature;

use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_creates_studio_with_its_first_owner(): void
    {
        $platformAdmin = User::create([
            'name' => 'Platform Admin', 'email' => 'platform-onboarding@test.az', 'password' => 'secret123',
            'role' => 'owner', 'is_platform_admin' => true,
        ]);

        Livewire::actingAs($platformAdmin)
            ->test(CreateTenant::class)
            ->fillForm([
                'name' => 'Yeni Studio',
                'slug' => 'yeni-studio',
                'active' => true,
                'owner_name' => 'Studio Sahibi',
                'owner_email' => 'owner@yeni-studio.test',
                'owner_password' => 'OwnerPassword123!',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::where('slug', 'yeni-studio')->firstOrFail();
        $owner = User::withoutGlobalScope('tenant')->where('email', 'owner@yeni-studio.test')->firstOrFail();

        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertTrue($owner->isOwner());
        $this->assertTrue($owner->is_active);
        $this->assertTrue(Hash::check('OwnerPassword123!', $owner->password));
    }
}
