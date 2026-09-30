<?php

namespace App\Services\Brief;

use App\Models\BriefAnswer;
use App\Models\BriefQuestion;
use App\Models\BriefSection;
use App\Models\BriefTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Brif konstruktoru — studiyanın öz brifini qurması və sistem şablonunu
 * öz ehtiyacına görə redaktə etməsi.
 *
 * Fərdi brif eyni cədvəllərdə yaşayır (`brief_templates` → `brief_sections` →
 * `brief_questions`), ona görə portal renderi, cavab sanitizasiyası, proqres,
 * PDF və admin cavab cədvəli heç bir dəyişiklik olmadan işləyir. Bu servis
 * yalnız o sətirləri konstruktorun formasından DOĞRU ŞƏKİLDƏ qurmağa
 * cavabdehdir: açarlar unikal, variantlar renderin gözlədiyi formada.
 *
 * Sistem şablonu yerində dəyişdirilmir (bütün studiyalar üçün ortaqdır və
 * seeder onu git-dəki bankdan yenidən yazır). Onun əvəzinə `forkTemplate()`
 * studiyaya TAM nüsxə verir — copy-on-write, `Role` ilə eyni naxış.
 */
class BriefBuilderService
{
    /**
     * Konstruktorda təklif olunan sual tipləri. Hamısı portal renderində
     * (`section.blade.php`) və `BriefController::sanitiseAnswer()`-də artıq
     * dəstəklənir — burada yalnız insan üçün ad verilir. Bank-spesifik tiplər
     * (matrix, room_inventory, std_or_custom…) YENİ sual üçün təklif olunmur:
     * onların konfiqi əl ilə qurulmaq üçün deyil. Sistem şablonunun nüsxəsində
     * belə sual varsa, mətn/izah/məcburilik redaktə olunur, tip və konfiq qorunur.
     *
     * @return array<string, string>
     */
    public static function questionTypes(): array
    {
        return [
            'text' => 'Qısa mətn — müştəri öz cavabını yazır',
            'textarea' => 'Uzun mətn (bir neçə sətir)',
            'select' => 'Tək seçim — variantlardan biri',
            'multiselect' => 'Çox seçim — bir neçə variant (checkbox)',
            'image_select' => 'Şəkilli tək seçim — kartlardan biri',
            'image_multiselect' => 'Şəkilli çox seçim — bir neçə kart',
            'boolean' => 'Bəli / Xeyr',
            'number' => 'Rəqəm',
            'date' => 'Tarix',
            'file' => 'Fayl yükləmə',
        ];
    }

    /**
     * Bankın xüsusi tipləri — nüsxədə görünür, konstruktorda yaradılmır.
     *
     * @return array<string, string>
     */
    public static function specialTypeLabels(): array
    {
        return [
            'matrix' => 'Matris (sətir × sütun)',
            'room_inventory' => 'Otaqların siyahısı',
            'repeater' => 'Təkrarlanan qrup',
            'budget_range' => 'Büdcə aralığı',
            'std_or_custom' => 'Standart / fərdi',
            'color_swatch' => 'Rəng palitrası',
            'consent' => 'Razılıq',
            'image_rating' => 'Şəkil qiymətləndirmə',
        ];
    }

    public static function isBuilderType(string $type): bool
    {
        return array_key_exists($type, self::questionTypes());
    }

    /** Tipin insan üçün adı — cədvəldə və formada. */
    public static function typeLabel(string $type): string
    {
        return str(self::questionTypes()[$type] ?? self::specialTypeLabels()[$type] ?? $type)->before(' —')->toString();
    }

    /** Variant siyahısı tələb edən tiplər. */
    public static function typeHasOptions(string $type): bool
    {
        return in_array($type, ['select', 'multiselect', 'image_select', 'image_multiselect'], true);
    }

    /** Variantın özü şəkil kartı olan tiplər. */
    public static function typeHasImages(string $type): bool
    {
        return in_array($type, ['image_select', 'image_multiselect'], true);
    }

    /**
     * Yeni fərdi brif: şablon + onun yeganə bölməsi. Bölmə adı brifin adını
     * təkrarlayır — müştəri portalda bir kart görür və onu açır.
     */
    public function createTemplate(string $name, ?string $description, User $by): BriefTemplate
    {
        if ($by->tenant_id === null) {
            throw new InvalidArgumentException('Fərdi brif yalnız studiyaya bağlı istifadəçi tərəfindən yaradıla bilər.');
        }

        $template = BriefTemplate::create([
            'tenant_id' => $by->tenant_id,
            'created_by_user_id' => $by->id,
            'key' => 'custom-'.Str::lower(Str::random(12)),
            'level' => BriefTemplate::LEVEL_CUSTOM,
            'name' => ['az' => $name],
            'description' => filled($description) ? ['az' => $description] : null,
            'is_default' => false,
            'active' => true,
            'position' => 100,
        ]);

        BriefSection::create([
            'brief_template_id' => $template->id,
            'key' => $this->uniqueSectionKey($template),
            'name' => ['az' => $name],
            'intro' => null,
            'icon' => 'clipboard-document-list',
            'estimated_minutes' => 0,
            'position' => 1,
            'room_type' => null,
            'active' => true,
        ]);

        return $template->fresh();
    }

    /**
     * Sistem şablonunun studiya nüsxəsi — «Redaktə et» düyməsinin arxası.
     *
     * Nə köçürülür: şablonun adı/təsviri/səviyyəsi, bütün AKTİV bölmələr (otaq
     * bölmələri `room_type` ilə birlikdə) və onların AKTİV sualları — tip,
     * variantlar (şəkillər daxil), şərti məntiq, qrup, məcburilik.
     *
     * SUAL AÇARLARI QƏSDƏN EYNİ QALIR. Açar şərti məntiqin (`skip_logic`
     * başqa sualın açarına baxır), otaq inventarının, xülasə panelinin və
     * şablon keçidinin (`switchTemplate()` cavabları açar üzrə köçürür)
     * dayağıdır. Nəticədə orijinal şablonla doldurulmağa başlanmış brif
     * nüsxəyə keçəndə müştərinin cavabları itmir. Bölmə açarı isə qlobal
     * unikaldır, ona görə yenidən verilir.
     *
     * Bir studiya bir sistem şablonunun yalnız bir nüsxəsini saxlayır
     * (DB-də unikal indeks); ikinci çağırış mövcud nüsxəni qaytarır.
     */
    public function forkTemplate(BriefTemplate $system, User $by): BriefTemplate
    {
        if (! $system->isSystem()) {
            throw new InvalidArgumentException('Yalnız sistem şablonunun nüsxəsi yaradılır — bu brif onsuz da sizindir.');
        }

        if ($by->tenant_id === null) {
            throw new InvalidArgumentException('Nüsxə yalnız studiyaya bağlı istifadəçi tərəfindən yaradıla bilər.');
        }

        if ($existing = $system->forkFor($by->tenant_id)) {
            return $existing;
        }

        return DB::transaction(function () use ($system, $by): BriefTemplate {
            $fork = BriefTemplate::create([
                'tenant_id' => $by->tenant_id,
                'created_by_user_id' => $by->id,
                'forked_from_id' => $system->id,
                'key' => 'fork-'.$by->tenant_id.'-'.Str::lower(Str::random(10)),
                // Səviyyə qorunur: nüsxə seçim siyahısında orijinalın qrupunda
                // (Quick / Premium) görünür və `isQuick()` məntiqi dəyişmir.
                'level' => $system->level,
                'name' => $system->getTranslations('name'),
                'description' => $system->getTranslations('description') ?: null,
                'is_default' => false,
                'active' => true,
                'position' => $system->position,
            ]);

            $sections = BriefSection::query()
                ->where('brief_template_id', $system->id)
                ->where('active', true)
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($sections as $section) {
                $copy = $section->replicate(['key', 'brief_template_id']);
                $copy->brief_template_id = $fork->id;
                $copy->key = $this->uniqueSectionKey($fork, $section->key);
                $copy->save();

                $questions = BriefQuestion::query()
                    ->where('brief_section_id', $section->id)
                    ->where('active', true)
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get();

                foreach ($questions as $question) {
                    $clone = $question->replicate(['brief_section_id']);
                    $clone->brief_section_id = $copy->id;
                    $clone->save();
                }
            }

            return $fork->fresh();
        });
    }

    /**
     * Ad/təsvir yenilənir. Yalnız AZ yazılır, digər dillər qorunur — nüsxədə
     * orijinalın en/ru adları var və forma onları göstərmir.
     *
     * Tək bölməli brifdə bölmənin adı da dəyişir — portalda ikisi eyni şeyi
     * göstərir. Çox bölməli brifdə (sistem nüsxəsi, bölmə əlavə edilmiş fərdi
     * brif) birinci bölmə öz adını saxlayır: «Ümumi məlumat» brifin adı deyil.
     */
    public function renameTemplate(BriefTemplate $template, string $name, ?string $description): void
    {
        $template->setTranslation('name', 'az', $name);

        if (filled($description)) {
            $template->setTranslation('description', 'az', $description);
        } else {
            $template->forgetTranslation('description', 'az');
        }

        $template->save();

        if (BriefSection::where('brief_template_id', $template->id)->count() === 1) {
            $section = BriefSection::where('brief_template_id', $template->id)->first();
            $section?->setTranslation('name', 'az', $name)->save();
        }
    }

    /**
     * Konstruktor formasından gələn məlumatı sual sətrinə çevirir.
     *
     * @param  array<string, mixed>  $data  label, type, help, is_required,
     *                                      allows_designer_choice, position, option_list,
     *                                      brief_section_id (istəyə görə)
     */
    public function addQuestion(BriefTemplate $template, array $data): BriefQuestion
    {
        $this->assertCustom($template);

        $section = filled($data['brief_section_id'] ?? null)
            ? $this->sectionOf($template, (int) $data['brief_section_id'])
            : ($template->primarySection ?? throw new InvalidArgumentException('Brifin bölməsi yoxdur.'));

        $type = (string) ($data['type'] ?? 'text');

        if (! self::isBuilderType($type)) {
            throw new InvalidArgumentException('Naməlum sual tipi: '.$type);
        }

        $attributes = $this->questionAttributes($data);
        $attributes['brief_section_id'] = $section->id;
        $attributes['key'] = $this->uniqueQuestionKey($section);
        $attributes['position'] = filled($data['position'] ?? null)
            ? (int) $data['position']
            : ((int) $section->questions()->max('position') + 1);
        $attributes['active'] = true;
        $attributes['skip_logic'] = null;
        $attributes['group'] = null;
        $attributes['supports_inspiration'] = false;

        return BriefQuestion::create($attributes);
    }

    /** @param  array<string, mixed>  $data */
    public function updateQuestion(BriefQuestion $question, array $data): BriefQuestion
    {
        $template = $question->template() ?? throw new InvalidArgumentException('Sualın şablonu tapılmadı.');
        $this->assertCustom($template);

        $attributes = $this->questionAttributes($data, $question);

        if (filled($data['position'] ?? null)) {
            $attributes['position'] = (int) $data['position'];
        }

        if (filled($data['brief_section_id'] ?? null) && (int) $data['brief_section_id'] !== (int) $question->brief_section_id) {
            $attributes['brief_section_id'] = $this->moveTarget($template, $question, (int) $data['brief_section_id'])->id;
        }

        // `skip_logic`, `group`, `supports_inspiration` forma sahəsi deyil —
        // toxunulmur. Əvvəl hər redaktə onları sıfırlayırdı; sistem nüsxəsində
        // bu, şərti məntiqi səssizcə silərdi (sual hamıya görünməyə başlayardı).
        $question->forceFill($attributes)->save();

        return $question->fresh();
    }

    /**
     * Sualı konstruktordan çıxarır. Cavabı olan sual SİLİNMİR, deaktiv edilir:
     * `brief_answers` ona bağlıdır və silmək müştərinin artıq yazdığını adminin
     * cavab cədvəlindən itirərdi. Cavabsız sual isə həqiqətən silinir.
     *
     * @return bool true — silindi, false — deaktiv edildi
     */
    public function removeQuestion(BriefQuestion $question): bool
    {
        $this->assertCustom($question->template() ?? throw new InvalidArgumentException('Sualın şablonu tapılmadı.'));

        if (BriefAnswer::query()->where('brief_question_id', $question->id)->exists()) {
            $question->forceFill(['active' => false])->save();

            return false;
        }

        $question->delete();

        return true;
    }

    /**
     * Yeni bölmə — çox bölməli brif üçün. Otaq bölməsi yaradılmır: onun
     * sayı otaq inventarından hesablanır və bank məntiqinə bağlıdır.
     *
     * @param  array<string, mixed>  $data  name, intro, position
     */
    public function addSection(BriefTemplate $template, array $data): BriefSection
    {
        $this->assertCustom($template);

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Bölmənin adı boş ola bilməz.');
        }

        $intro = trim((string) ($data['intro'] ?? ''));

        return BriefSection::create([
            'brief_template_id' => $template->id,
            'key' => $this->uniqueSectionKey($template),
            'name' => ['az' => $name],
            'intro' => $intro !== '' ? ['az' => $intro] : null,
            'icon' => 'clipboard-document-list',
            'estimated_minutes' => 0,
            'position' => filled($data['position'] ?? null)
                ? (int) $data['position']
                : ((int) BriefSection::where('brief_template_id', $template->id)->max('position') + 1),
            'room_type' => null,
            'active' => true,
        ]);
    }

    /** @param  array<string, mixed>  $data  name, intro, position, active */
    public function updateSection(BriefSection $section, array $data): BriefSection
    {
        $this->assertCustom($section->template ?? throw new InvalidArgumentException('Bölmənin şablonu tapılmadı.'));

        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '') {
            throw new InvalidArgumentException('Bölmənin adı boş ola bilməz.');
        }

        $section->setTranslation('name', 'az', $name);

        $intro = trim((string) ($data['intro'] ?? ''));

        if ($intro !== '') {
            $section->setTranslation('intro', 'az', $intro);
        } else {
            $section->forgetTranslation('intro', 'az');
        }

        if (filled($data['position'] ?? null)) {
            $section->position = (int) $data['position'];
        }

        if (array_key_exists('active', $data)) {
            $active = (bool) $data['active'];

            if (! $active && $section->active && $this->isLastActiveSection($section)) {
                throw new InvalidArgumentException('Brifdə ən azı bir aktiv bölmə qalmalıdır.');
            }

            $section->active = $active;
        }

        $section->save();

        return $section->fresh();
    }

    /**
     * Bölməni silir — yalnız içində heç bir sual (deaktiv də) yoxdursa.
     * Sualı olan bölmə deaktiv edilir: suallara bağlı cavablar və bölmə
     * vəziyyətləri (`brief_section_states`) itməməlidir.
     */
    public function deleteSection(BriefSection $section): void
    {
        $this->assertCustom($section->template ?? throw new InvalidArgumentException('Bölmənin şablonu tapılmadı.'));

        if ($section->isRoomSection()) {
            throw new InvalidArgumentException('Otaq bölməsi silinmir — lazım deyilsə deaktiv edin.');
        }

        if (BriefQuestion::where('brief_section_id', $section->id)->exists()) {
            throw new InvalidArgumentException('Bölmədə suallar var. Əvvəlcə sualları silin və ya bölməni deaktiv edin.');
        }

        if ($section->active && $this->isLastActiveSection($section)) {
            throw new InvalidArgumentException('Brifdə ən azı bir aktiv bölmə qalmalıdır.');
        }

        $section->delete();
    }

    /**
     * Formanın sahələri → sətir. `options` yalnız variantlı tiplərdə saxlanılır;
     * tip dəyişib variantsız olubsa köhnə variantlar silinir ki, render onları
     * yanlışlıqla göstərməsin.
     *
     * Bankın xüsusi tipli sualı (matrix, room_inventory…) üçün tip və konfiq
     * (`options`) toxunulmaz qalır — yalnız mətn, izah və bayraqlar dəyişir.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function questionAttributes(array $data, ?BriefQuestion $existing = null): array
    {
        $type = (string) ($data['type'] ?? $existing?->type ?? 'text');

        $special = $existing !== null && ! self::isBuilderType($existing->type);

        if ($special) {
            // Xüsusi tipi başqa tipə çevirmək onun konfiqini (matris sətirləri,
            // otaq növləri) mənasız edərdi — tip sabitdir.
            $type = $existing->type;
        } elseif (! self::isBuilderType($type)) {
            throw new InvalidArgumentException('Naməlum sual tipi: '.$type);
        }

        $label = trim((string) ($data['label'] ?? ''));

        if ($label === '') {
            throw new InvalidArgumentException('Sualın mətni boş ola bilməz.');
        }

        $help = trim((string) ($data['help'] ?? ''));

        // Tərcümələr: yalnız AZ forma sahəsidir, en/ru (nüsxədə ola bilər) qorunur.
        $labels = $existing?->getTranslations('label') ?? [];
        $labels['az'] = $label;

        $helps = $existing?->getTranslations('help') ?? [];

        if ($help !== '') {
            $helps['az'] = $help;
        } else {
            unset($helps['az']);
        }

        return [
            'label' => $labels,
            'help' => $helps !== [] ? $helps : null,
            'type' => $type,
            'options' => match (true) {
                $special => $existing->options,
                self::typeHasOptions($type) => $this->normaliseOptions($type, (array) ($data['option_list'] ?? []), $existing?->options ?? []),
                default => null,
            },
            'is_required' => (bool) ($data['is_required'] ?? false),
            'allows_designer_choice' => (bool) ($data['allows_designer_choice'] ?? false),
        ];
    }

    /**
     * Konstruktorun variant sətirlərini renderin gözlədiyi formaya salır:
     * `[{value, label: {az}, image_url?}]`.
     *
     * `value` müştərinin cavabında saxlanan açardır — sonradan dəyişməməlidir,
     * yoxsa köhnə cavablar «tanınmayan variant» olar. Ona görə formadan gələn
     * `value` varsa qorunur, yoxdursa (yeni sətir) təsadüfi açar verilir;
     * etiketdən slug çıxarılmır, çünki etiketi düzəldəndə açar da dəyişərdi.
     *
     * Mövcud variant (eyni `value`) formanın bilmədiyi sahələrini SAXLAYIR:
     * en/ru etiketləri, bankın `colors`, `images` kimi əlavələri. Forma yalnız
     * AZ etiketi və şəkli dəyişir.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, mixed>  $previous
     * @return array<int, array<string, mixed>>
     */
    public function normaliseOptions(string $type, array $rows, array $previous = []): array
    {
        $previousByValue = [];

        foreach ($previous as $option) {
            if (is_array($option) && isset($option['value']) && is_scalar($option['value'])) {
                $previousByValue[(string) $option['value']] = $option;
            }
        }

        $options = [];
        $seen = [];

        foreach (array_values($rows) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            $image = self::typeHasImages($type) ? $this->firstPath($row['image_url'] ?? null) : null;

            // Boş sətir — nə etiket, nə şəkil — repeater-in «əlavə et» basılıb
            // doldurulmamış qalığıdır; saxlamırıq.
            if ($label === '' && $image === null) {
                continue;
            }

            $value = trim((string) ($row['value'] ?? ''));

            if ($value === '' || isset($seen[$value])) {
                $value = 'opt-'.Str::lower(Str::random(8));
            }

            $seen[$value] = true;

            $option = $previousByValue[$value] ?? [];
            $labels = is_array($option['label'] ?? null) ? $option['label'] : [];
            // Şəkilli kartda etiket boş qala bilər — kartın özü cavabdır;
            // amma `optionLabel()` boş sətri açarla əvəz etməsin deyə
            // yer tutucusu yazırıq.
            $labels['az'] = $label !== '' ? $label : 'Variant '.(count($options) + 1);

            $option['value'] = $value;
            $option['label'] = $labels;

            if ($image !== null) {
                $option['image_url'] = $image;
            } else {
                unset($option['image_url']);
            }

            $options[] = $option;
        }

        if (count($options) < 2) {
            throw new InvalidArgumentException('Seçimli sualda ən azı iki variant olmalıdır.');
        }

        return $options;
    }

    /** FileUpload tək fayl üçün də massiv qaytara bilər — yol çıxarılır. */
    private function firstPath(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = collect($value)->filter(fn ($v) => is_string($v) && $v !== '')->first();
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Bölmə bu şablona aiddirmi — id formadan (payload-dan) gəlir. */
    private function sectionOf(BriefTemplate $template, int $sectionId): BriefSection
    {
        return BriefSection::query()
            ->where('brief_template_id', $template->id)
            ->whereKey($sectionId)
            ->first() ?? throw new InvalidArgumentException('Bölmə bu brifə aid deyil.');
    }

    /**
     * Sualı başqa bölməyə köçürmək. Otaq bölməsinin sualı hər otaq üçün ayrıca
     * cavablanır (`brief_room_id`); ümumi bölmə ilə otaq bölməsi arasında
     * köçürmə mövcud cavabların mənasını pozardı — qadağandır. Açar hədəf
     * bölmədə unikal olmalıdır (`unique(brief_section_id, key)`).
     */
    private function moveTarget(BriefTemplate $template, BriefQuestion $question, int $sectionId): BriefSection
    {
        $target = $this->sectionOf($template, $sectionId);
        $current = $question->section;

        if ($current !== null && $current->isRoomSection() !== $target->isRoomSection()) {
            throw new InvalidArgumentException('Sual ümumi bölmə ilə otaq bölməsi arasında köçürülə bilməz.');
        }

        if (BriefQuestion::where('brief_section_id', $target->id)->where('key', $question->key)->exists()) {
            throw new InvalidArgumentException('Hədəf bölmədə eyni açarlı sual artıq var.');
        }

        return $target;
    }

    private function isLastActiveSection(BriefSection $section): bool
    {
        return BriefSection::query()
            ->where('brief_template_id', $section->brief_template_id)
            ->where('active', true)
            ->whereKeyNot($section->id)
            ->doesntExist();
    }

    /** `(brief_section_id, key)` unikaldır — bölmə daxilində toqquşmasız açar. */
    private function uniqueQuestionKey(BriefSection $section): string
    {
        do {
            $key = 'q-'.Str::lower(Str::random(10));
        } while (BriefQuestion::where('brief_section_id', $section->id)->where('key', $key)->exists());

        return $key;
    }

    /**
     * `brief_sections.key` QLOBAL unikaldır (64 simvol). Bank açarları heç vaxt
     * `custom-`/`f{id}-` ilə başlamır, ona görə toqquşma yalnız nəzəri olaraq
     * mümkündür — yenə də yoxlanılır. Nüsxədə orijinal açar oxunaqlılıq üçün
     * saxlanılır (`f12-general`).
     */
    private function uniqueSectionKey(BriefTemplate $template, ?string $base = null): string
    {
        $prefix = $base !== null ? 'f'.$template->id.'-' : 'custom-'.$template->id.'-';
        $stem = $base !== null ? substr($base, 0, 64 - strlen($prefix) - 7) : '';

        $key = $prefix.($stem !== '' ? $stem : Str::lower(Str::random(8)));

        while (BriefSection::where('key', $key)->exists()) {
            $key = $prefix.($stem !== '' ? $stem.'-' : '').Str::lower(Str::random(6));
        }

        return $key;
    }

    /**
     * Sistem bankına toxunmaq qadağandır: onun sualları git-dədir və seeder-in
     * növbəti işləməsi paneldəki dəyişikliyi səssizcə geri qaytarardı. Studiya
     * sistem şablonunu dəyişmək istəyirsə, `forkTemplate()` nüsxə verir.
     */
    private function assertCustom(BriefTemplate $template): void
    {
        if (! $template->isCustom()) {
            throw new InvalidArgumentException('Sistem şablonu yerində dəyişdirilmir — «Redaktə et» ilə studiyanızın nüsxəsini yaradın.');
        }
    }
}
