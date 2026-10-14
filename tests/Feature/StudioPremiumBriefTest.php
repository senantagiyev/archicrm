<?php

namespace Tests\Feature;

use App\Filament\Resources\BriefTemplateResource\Pages\EditBriefTemplate;
use App\Filament\Resources\BriefTemplateResource\Pages\ListBriefTemplates;
use App\Filament\Resources\BriefTemplateResource\RelationManagers\QuestionsRelationManager;
use App\Filament\Resources\TenantResource\Pages\CreateTenant;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Brief\BriefBuilderService;
use App\Services\Brief\BriefService;
use App\Support\AccessMatrix;
use App\Support\TenantContext;
use Database\Seeders\BriefQuestionBankSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportRedirects\SupportRedirects;
use Livewire\Livewire;
use Tests\Support\StudioWorld;
use Tests\TestCase;

/**
 * Studiyanın standart «Premium brif (şəkilli)» şablonu — sistemin Roomix
 * nümunəsində qurulmuş tam şəkilli brifinin studiyaya məxsus nüsxəsi.
 *
 * Müştəri tələbi (2026-10-14): o brif şablon kimi qalsın, defolt olsun və digər
 * briflər kimi redaktə edilə bilsin.
 */
class StudioPremiumBriefTest extends TestCase
{
    use RefreshDatabase;

    private StudioWorld $studio;

    private BriefTemplate $system;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BriefQuestionBankSeeder::class);
        $this->studio = StudioWorld::make('premium');
        $this->system = BriefTemplate::default();
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        AccessMatrix::flushCache();

        parent::tearDown();
    }

    public function test_the_studio_gets_an_editable_default_copy_with_every_question_and_image(): void
    {
        // Sistem sualının variantına şəkil bağlanıb (prod-da admin yükləyib).
        $style = BriefQuestion::whereIn('brief_section_id', $this->system->sections()->select('id'))
            ->where('key', 'style_preferences')->firstOrFail();
        $options = $style->options;
        $options[0]['image_url'] = 'brief/options/style-test.jpg';
        $options[1]['images'] = ['brief/options/sample-test.jpg'];
        $style->forceFill(['options' => $options])->save();

        $premium = app(BriefBuilderService::class)->provisionStudioPremium($this->studio->tenant->id, $this->studio->user('owner')->id);

        $this->assertSame('Premium brif (şəkilli)', $premium->getTranslation('name', 'az'));
        $this->assertSame($this->system->id, $premium->forked_from_id);
        $this->assertTrue($premium->isCustom(), 'Studiyanındır — birbaşa redaktə olunur.');
        $this->assertSame($this->system->questions()->count(), $premium->questions()->count());
        $this->assertSame(
            $this->system->sections()->where('active', true)->count(),
            BriefSection::where('brief_template_id', $premium->id)->count(),
        );

        $copy = BriefQuestion::whereIn('brief_section_id', BriefSection::where('brief_template_id', $premium->id)->select('id'))
            ->where('key', 'style_preferences')->firstOrFail();
        $this->assertSame('brief/options/style-test.jpg', $copy->options[0]['image_url'], 'Şəkillər nüsxəyə keçməlidir.');
        $this->assertSame(['brief/options/sample-test.jpg'], $copy->options[1]['images']);

        // Defolt: yeni layihənin brifi bu şablonla açılır.
        $this->assertSame($premium->id, BriefTemplate::defaultFor($this->studio->tenant->id)->id);
        $brief = app(TenantContext::class)->actingAs($this->studio->tenant->id, fn () => app(BriefService::class)->forProject($this->studio->project));
        $this->assertSame($premium->id, $brief->brief_template_id);

        // İdempotent: ikinci çağırış yeni nüsxə yaratmır.
        app(BriefBuilderService::class)->provisionStudioPremium($this->studio->tenant->id);
        $this->assertSame(1, BriefTemplate::where('tenant_id', $this->studio->tenant->id)->where('forked_from_id', $this->system->id)->count());
    }

    public function test_the_admin_edits_the_premium_brief_directly_like_any_other_brief(): void
    {
        $premium = app(BriefBuilderService::class)->provisionStudioPremium($this->studio->tenant->id);
        $owner = $this->asOwner();

        $this->assertTrue($owner->can('update', $premium));

        Livewire::test(ListBriefTemplates::class)
            ->assertCanSeeTableRecords([$premium])
            ->assertCanNotSeeTableRecords([$this->system])
            ->assertTableActionVisible('edit', $premium);

        $this->assertTrue(QuestionsRelationManager::canViewForRecord($premium, EditBriefTemplate::class));

        $question = BriefQuestion::whereIn('brief_section_id', BriefSection::where('brief_template_id', $premium->id)->select('id'))
            ->where('key', 'object_address')->firstOrFail();

        app(BriefBuilderService::class)->updateQuestion($question, ['label' => 'Obyektin dəqiq ünvanı', 'type' => $question->type]);
        $this->assertSame('Obyektin dəqiq ünvanı', $question->fresh()->getTranslation('label', 'az'));

        // Sistem şablonu toxunulmaz qalır.
        $systemQuestion = BriefQuestion::whereIn('brief_section_id', $this->system->sections()->select('id'))
            ->where('key', 'object_address')->firstOrFail();
        $this->assertNotSame('Obyektin dəqiq ünvanı', $systemQuestion->getTranslation('label', 'az'));
    }

    public function test_a_new_studio_starts_with_the_premium_brief(): void
    {
        $platformAdmin = User::create([
            'name' => 'Platform Admin', 'email' => 'platform-premium@test.az', 'password' => 'secret123',
            'role' => 'owner', 'is_platform_admin' => true,
        ]);

        Livewire::actingAs($platformAdmin)
            ->test(CreateTenant::class)
            ->fillForm([
                'name' => 'Yeni Studio', 'slug' => 'premium-yeni', 'active' => true,
                'owner_name' => 'Studio Sahibi', 'owner_email' => 'owner@premium-yeni.test',
                'owner_password' => 'OwnerPassword123!',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::where('slug', 'premium-yeni')->firstOrFail();
        $premium = BriefTemplate::where('tenant_id', $tenant->id)->where('forked_from_id', $this->system->id)->first();

        $this->assertNotNull($premium, 'Yeni studiya Premium brifsiz qalmamalıdır.');
        $this->assertSame('Premium brif (şəkilli)', $premium->getTranslation('name', 'az'));
        $this->assertSame($premium->id, BriefTemplate::defaultFor($tenant->id)->id);
    }

    private function asOwner(): User
    {
        $user = $this->studio->user('owner');

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
