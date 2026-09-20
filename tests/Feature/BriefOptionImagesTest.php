<?php

namespace Tests\Feature;

use App\Filament\Resources\BriefQuestionResource;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\User;
use App\Policies\BriefQuestionPolicy;
use Database\Seeders\BriefQuestionBankSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Variant şəkilləri: admin ekranının əhatəsi, icazələr və şəkillərin seeder
 * işləyəndə itməməsi.
 *
 * İcazə testi xüsusilə vacibdir: bu panel `strictAuthorization()` rejimində
 * deyil, yəni policy-si olmayan model üçün Filament icazə VERİR. Policy səhvən
 * silinsə, bu test onu tutur.
 */
class BriefOptionImagesTest extends TestCase
{
    use RefreshDatabase;

    private function section(): BriefSection
    {
        return BriefSection::create([
            'key' => 'aesthetics_test',
            'name' => ['az' => 'Estetika'],
            'position' => 1,
            'active' => true,
        ]);
    }

    private function imageQuestion(BriefSection $section): BriefQuestion
    {
        return BriefQuestion::create([
            'brief_section_id' => $section->id,
            'key' => 'style_preferences_test',
            'label' => ['az' => 'Üslublar'],
            'type' => 'image_multiselect',
            'options' => [
                ['value' => 'neoclassic', 'label' => ['az' => 'Neoklassika']],
                ['value' => 'loft', 'label' => ['az' => 'Loft']],
            ],
            'is_required' => false,
            'allows_designer_choice' => true,
            'position' => 0,
            'active' => true,
        ]);
    }

    /**
     * Siyahı YALNIZ şəkil qəbul edən sualları göstərir — qalan 100+ sual
     * ekranı istifadəsiz edərdi.
     */
    public function test_only_image_capable_questions_are_listed(): void
    {
        $section = $this->section();
        $image = $this->imageQuestion($section);

        $inspire = BriefQuestion::create([
            'brief_section_id' => $section->id, 'key' => 'wall_materials_test',
            'label' => ['az' => 'Divar materialları'], 'type' => 'multiselect',
            'supports_inspiration' => true,
            'options' => [['value' => 'paint', 'label' => ['az' => 'Boya']]],
            'is_required' => false, 'allows_designer_choice' => true, 'position' => 1, 'active' => true,
        ]);

        $plain = BriefQuestion::create([
            'brief_section_id' => $section->id, 'key' => 'plain_text_test',
            'label' => ['az' => 'Sərbəst mətn'], 'type' => 'textarea',
            'options' => null,
            'is_required' => false, 'allows_designer_choice' => false, 'position' => 2, 'active' => true,
        ]);

        $listed = BriefQuestionResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains($image->id, $listed);
        $this->assertContains($inspire->id, $listed, 'İlham bayraqlı sual da siyahıda olmalıdır.');
        $this->assertNotContains($plain->id, $listed, 'Şəkil qəbul etməyən sual siyahıya düşməməlidir.');
    }

    /**
     * Sual bankın nəzarətindədir: paneldən yaratmaq/silmək bağlıdır, çünki
     * belə sətir seeder-in növbəti işləməsində bankla ziddiyyətə düşərdi.
     */
    public function test_questions_cannot_be_created_or_deleted_from_the_panel(): void
    {
        $owner = User::create([
            'name' => 'Sahib', 'email' => 'owner@test.az', 'password' => 'secret123', 'role' => 'owner',
        ]);
        $question = $this->imageQuestion($this->section());
        $policy = new BriefQuestionPolicy;

        $this->assertFalse($policy->create($owner));
        $this->assertFalse($policy->delete($owner, $question));
        $this->assertFalse($policy->forceDelete($owner, $question));
        $this->assertFalse($policy->restore($owner, $question));

        // Şəkilləri isə dəyişə bilir.
        $this->assertTrue($policy->update($owner, $question));
        $this->assertTrue($policy->viewAny($owner));
    }

    /**
     * Brief domeni bağlı olan istifadəçi ekranı görmür. Policy silinsə,
     * Filament defolt olaraq icazə verərdi — bu test məhz onu tutur.
     */
    public function test_user_without_brief_access_is_denied(): void
    {
        $visualizer = User::create([
            'name' => 'Vizualizator', 'email' => 'viz@test.az', 'password' => 'secret123', 'role' => 'visualizer',
        ]);
        $question = $this->imageQuestion($this->section());
        $policy = new BriefQuestionPolicy;

        $this->assertFalse(
            $policy->update($visualizer, $question),
            'Brief domeni Full olmayan istifadəçi şəkilləri dəyişə bilməməlidir.',
        );
    }

    /**
     * Ən kritik hal: bank seeder-i yenidən işləyəndə admin yüklədiyi şəkillər
     * QALMALIDIR. Əks halda hər deploy şəkilləri silərdi.
     */
    public function test_seeder_keeps_uploaded_images(): void
    {
        $this->seed(BriefQuestionBankSeeder::class);

        $question = BriefQuestion::where('key', 'style_preferences')->firstOrFail();
        $options = $question->options;

        // Admin şəkil yüklədi.
        $options[0]['image_url'] = 'brief/options/neoclassic.webp';
        $question->update(['options' => $options]);

        // Deploy: bank yenidən səpilir.
        $this->seed(BriefQuestionBankSeeder::class);

        $after = BriefQuestion::where('key', 'style_preferences')->firstOrFail()->options;
        $neoclassic = collect($after)->firstWhere('value', 'neoclassic');

        $this->assertSame(
            'brief/options/neoclassic.webp',
            $neoclassic['image_url'] ?? null,
            'Seeder yüklənmiş şəkli silməməlidir.',
        );
    }
}
