<?php

namespace Tests\Feature;

use App\Enums\Domain;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Models\Role;
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
 * Müştəri şikayəti (2026-10-15): «Rollar»da mövcud rolu redaktə edib yadda
 * saxlayanda redaktə yerinə YENİ rol yaranır, bəzən isə «açar artıq istifadə
 * olunub» xətası çıxır.
 *
 * Standart rol studiyada redaktə olunanda studiyanın öz surəti yaranır
 * (copy-on-write) — bu qəsdəndir. Xəta siyahıda idi: həm surət, həm orijinal
 * göstərilirdi; orijinalı yenidən redaktə etmək ikinci surət yaratmağa
 * çalışırdı və unikal açar qaydasına ilişirdi.
 */
class RoleEditCopyTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $alpha;

    private StudioWorld $beta;

    private Role $designer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        AccessMatrix::flushCache();
        $this->alpha = StudioWorld::make('alpha');
        $this->beta = StudioWorld::make('beta');
        $this->designer = Role::whereNull('tenant_id')->where('key', 'designer')->sole();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_editing_a_standard_role_twice_keeps_one_row(): void
    {
        $owner = $this->asOwner($this->alpha);

        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $this->designer->getKey()])
            ->fillForm(['name' => 'Memar', 'levels.payments' => 1])
            ->call('save')
            ->assertHasNoFormErrors();

        $copy = Role::where('tenant_id', $this->alpha->tenant->id)->where('key', 'designer')->sole();
        $this->assertSame('Memar', $copy->name);

        // Siyahıda «designer» bir dəfə — studiyanın versiyası; orijinal gizlənir.
        Livewire::actingAs($owner)
            ->test(ListRoles::class)
            ->assertCanSeeTableRecords([$copy])
            ->assertCanNotSeeTableRecords([$this->designer])
            ->assertSee('standart rol, studiyanızda dəyişdirilib');

        // İkinci redaktə həmin sətri yeniləyir, yeni sətir yaratmır.
        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $copy->getKey()])
            ->fillForm(['name' => 'Baş memar'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Role::where('tenant_id', $this->alpha->tenant->id)->where('key', 'designer')->count());
        $this->assertSame('Baş memar', $copy->fresh()->name);
        $this->assertSame(1, AccessMatrix::level($this->alpha->user('designer'), Domain::Payments)->value);
    }

    public function test_an_old_link_to_the_standard_role_edits_the_studio_copy(): void
    {
        $owner = $this->asOwner($this->alpha);

        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $this->designer->getKey()])
            ->fillForm(['name' => 'Memar'])
            ->call('save');

        // Köhnə tab / əlfəcin hələ orijinalın id-sini göstərir — əvvəl burada
        // «açar artıq istifadə olunub» xətası çıxırdı.
        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $this->designer->getKey()])
            ->assertSchemaStateSet(['name' => 'Memar'])
            ->fillForm(['name' => 'Memar 2'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Role::where('tenant_id', $this->alpha->tenant->id)->where('key', 'designer')->count());
        $this->assertSame('Memar 2', Role::where('tenant_id', $this->alpha->tenant->id)->where('key', 'designer')->value('name'));
        $this->assertSame('Baş dizayner / Memar', $this->designer->fresh()->name, 'Orijinal platforma sətri dəyişməməlidir.');
    }

    public function test_the_other_studio_keeps_the_standard_role(): void
    {
        Livewire::actingAs($this->asOwner($this->alpha))
            ->test(EditRole::class, ['record' => $this->designer->getKey()])
            ->fillForm(['name' => 'Memar'])
            ->call('save');

        Livewire::actingAs($this->asOwner($this->beta))
            ->test(ListRoles::class)
            ->assertCanSeeTableRecords([$this->designer]);
    }

    public function test_reverting_the_copy_brings_the_standard_role_back(): void
    {
        $owner = $this->asOwner($this->alpha);

        Livewire::actingAs($owner)
            ->test(EditRole::class, ['record' => $this->designer->getKey()])
            ->fillForm(['name' => 'Memar'])
            ->call('save');
        $copy = Role::where('tenant_id', $this->alpha->tenant->id)->where('key', 'designer')->sole();

        Livewire::actingAs($owner)
            ->test(ListRoles::class)
            ->callTableAction('delete', $copy);

        $this->assertModelMissing($copy);
        Livewire::actingAs($owner)
            ->test(ListRoles::class)
            ->assertCanSeeTableRecords([$this->designer]);
    }

    public function test_a_custom_role_cannot_take_a_standard_roles_key(): void
    {
        $owner = $this->asOwner($this->alpha);

        Livewire::actingAs($owner)
            ->test(CreateRole::class)
            ->fillForm(['key' => 'designer', 'name' => 'Saxta dizayner'])
            ->call('create')
            ->assertHasFormErrors(['key' => 'not_in']);

        $this->assertSame(0, Role::where('tenant_id', $this->alpha->tenant->id)->count());
    }

    private function asOwner(StudioWorld $studio): User
    {
        $owner = $studio->user('owner');

        AccessMatrix::flushCache();
        $this->actingAs($owner);
        Filament::setCurrentPanel('app');
        app(TenantContext::class)->set($studio->tenant->id);

        return $owner;
    }
}
