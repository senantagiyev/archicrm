<?php

/**
 * Brif sual bankı — «ARCHI CRM — BRİF BÖLMƏSİ. YEKUN AUDİT VƏ TEXNİKİ TAPŞIRIQ»
 * (v1.0, 05.09.2026) Part 8.2 / Part 9 / Part 10 üzrə.
 *
 * Bölmələrin sırası (Part 8.2, D1):
 *   1 Sizin haqqınızda → 2 Obyekt → 3 Format və büdcə (NEW) → 4 Estetika →
 *   5 Örtük materialları → 6 İşıqlandırma → 7 Mühəndislik (D5: otaqlardan əvvəl) →
 *   8 Otaqlar (dinamik tərkib) → 9 Komplektasiya (D6: sondan əvvəlki) →
 *   10 Final: istəklər və kontaktlar.
 *
 * Sual formatı (assoc):
 *   key, label (string|array), type, options, required, delegatable, help, skip
 * Köhnə pozisiyalı format da dəstəklənir: [key,label,type,options,required,delegatable,skip]
 *
 * type dəyərləri: text · textarea · number · select · multiselect · boolean ·
 *   date · image_select · matrix · color_swatch · budget_range · room_inventory ·
 *   consent · file
 */
$opt = fn (array $pairs) => collect($pairs)
    ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => ['az' => $label]])
    ->values()
    ->all();

// ── Otaq bloklarının yenidən istifadə edilə bilən komponentləri (Part 9.8.3) ──

$roomStorage = fn (string $p) => [
    'key' => $p.'_storage', 'label' => 'Saxlama və mebel ehtiyacları', 'type' => 'multiselect',
    'options' => $opt([
        'wardrobe' => 'Şkaf', 'walkin' => 'Qarderob otağı', 'open_shelves' => 'Açıq rəflər',
        'built_in' => 'Quraşdırılmış mebel', 'pantograph' => 'Pantoqraf', 'large_items' => 'İrigabaritli əşyaların saxlanması',
        'designer' => 'Dizaynerin ixtiyarına',
    ]),
    'required' => false, 'delegatable' => true,
];

$roomDecor = fn (string $p) => [
    'key' => $p.'_decor', 'label' => 'Dekor', 'type' => 'multiselect',
    'options' => $opt([
        'paintings' => 'Rəsmlər', 'sculptures' => 'Heykəllər', 'vases' => 'Vazalar',
        'panno' => 'Dekorativ panno', 'mirrors' => 'Güzgülər', 'family_photos' => 'Ailə fotoları',
        'carpets' => 'Xalçalar', 'designer' => 'Dizaynerin ixtiyarına',
    ]),
    'required' => false, 'delegatable' => true,
];

$roomGreenery = fn (string $p) => [
    'key' => $p.'_greenery', 'label' => 'Yaşıllaşdırma', 'type' => 'multiselect',
    'options' => $opt([
        'fitopanno' => 'Fitopanno', 'pots' => 'Kaşpoda bitkilər', 'ceiling' => 'Tavan yaşıllaşdırması',
        'moss' => 'Mamırdan panellər', 'large' => 'İrigabaritli bitkilər', 'winter_garden' => 'Qış bağı',
        'none' => 'Lazım deyil', 'designer' => 'Dizaynerin ixtiyarına',
    ]),
    'required' => false, 'delegatable' => true,
];

$roomNotes = fn (string $p) => [
    'key' => $p.'_notes', 'label' => 'Əlavə istəklər', 'type' => 'textarea',
    'options' => null, 'required' => false, 'delegatable' => false,
];

/** Otaq bölməsi qısa yaradıcısı. */
$roomSection = fn (string $key, string $roomType, array $name, array $questions) => [
    'key' => $key,
    'name' => $name,
    'room_type' => $roomType,
    'estimated_minutes' => 2,
    'questions' => $questions,
];

return [

    // ══════════════════ BÖLMƏ 1 — SİZİN HAQQINIZDA (Part 9.1) ══════════════════
    [
        'key' => 'about_you',
        'name' => ['az' => 'Sizin haqqınızda', 'ru' => 'О вас', 'en' => 'About you'],
        'icon' => 'user',
        'estimated_minutes' => 3,
        'questions' => [
            ['key' => 'household_members', 'label' => 'Ailə tərkibi — kim, neçə yaşında, hansı xüsusiyyətlərlə', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Bu, hər kəs üçün erqonomikanı nəzərə almağa kömək edəcək (ad, yaş, boy, əl üstünlüyü).'],
            ['key' => 'adults_count', 'label' => 'Evdə neçə böyük yaşayır?', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'hobbies_profession', 'label' => 'Maraqlar / peşə və evdə nə nəzərə alınmalıdır', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Hobbi zonalaşdırmanın hissəsinə çevrilə bilər.'],
            ['key' => 'frequent_guests', 'label' => 'Tez-tez qonaq qəbul edirsiniz?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'future_plans_5_10y', 'label' => '5–10 illik planlarınız', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: uşaq doğulması, uzaqdan iş, valideynlərin köçməsi.'],
            ['key' => 'has_pets', 'label' => 'Ev heyvanlarınız var?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'pet_type', 'label' => 'Heyvanın tipi', 'type' => 'multiselect',
                'options' => $opt(['cat' => 'Pişik', 'dog_big' => 'İt (böyük)', 'dog_small' => 'İt (kiçik)', 'other' => 'Digər']),
                'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'has_pets', 'operator' => 'equals', 'value' => '1']],
            ['key' => 'pet_zone_needs', 'label' => 'Heyvanlar üzrə xüsusi tələblər', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Yataq, caynaq itiləyən, WC, məhdudlaşdırıcılar.',
                'skip' => ['question' => 'has_pets', 'operator' => 'equals', 'value' => '1']],
            ['key' => 'has_allergies', 'label' => 'Ailədə allergiya var?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'allergy_details', 'label' => 'Allergiya detalları', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Diaqnoz qeyd etmək məcburi deyil — qaçınılmalı materiallar/faktorlar kifayətdir.',
                'skip' => ['question' => 'has_allergies', 'operator' => 'equals', 'value' => '1']],
            ['key' => 'works_from_home', 'label' => 'Evdən işləyirsiniz?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Bəli olduqda otaqlar bölməsində kabinet əlavə etməyi unutmayın.'],
            ['key' => 'smoking_indoors', 'label' => 'Evdə siqaret çəkilirmi?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'decision_maker', 'label' => 'Dizayn üzrə yekun qərarları kim qəbul edir?', 'type' => 'select',
                'options' => $opt(['me' => 'Hazırda cavab verən', 'both' => 'Hər iki tərəfdaş birgə', 'other' => 'Ailənin başqa üzvü']),
                'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'adults_count', 'operator' => 'gte', 'value' => 2]],
            ['key' => 'lead_source', 'label' => 'Bizi haradan öyrəndiniz?', 'type' => 'multiselect',
                'options' => $opt(['instagram' => 'Instagram', 'facebook' => 'Facebook', 'ads' => 'Reklam', 'friends' => 'Tanışlardan', 'word_of_mouth' => 'Ağızdan-ağıza', 'other' => 'Digər']),
                'required' => false, 'delegatable' => false],
            ['key' => 'why_chose_us', 'label' => 'Niyə məhz bizi seçdiniz?', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false],
        ],
    ],

    // ══════════════════ BÖLMƏ 2 — OBYEKT (Part 9.2) ══════════════════
    [
        'key' => 'object',
        'name' => ['az' => 'Obyekt', 'ru' => 'Объект', 'en' => 'The property'],
        'icon' => 'home',
        'estimated_minutes' => 5,
        'questions' => [
            ['key' => 'object_address', 'label' => 'Obyektin ünvanı', 'type' => 'text', 'options' => null, 'required' => true, 'delegatable' => false],
            ['key' => 'object_location_note', 'label' => 'Yerləşmə', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: rayon, yaşayış kompleksi, oriyentir.'],
            ['key' => 'object_type', 'label' => 'Obyektin tipi', 'type' => 'select',
                'options' => $opt(['apartment' => 'Mənzil', 'house' => 'Fərdi ev']),
                'required' => true, 'delegatable' => false],
            ['key' => 'floor_info', 'label' => 'Mərtəbə / mərtəbəlik', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: 12-dən 5.',
                'skip' => ['question' => 'object_type', 'operator' => 'equals', 'value' => 'apartment']],
            ['key' => 'house_floors_count', 'label' => 'Evin mərtəbə sayı', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'object_type', 'operator' => 'equals', 'value' => 'house']],
            ['key' => 'total_area_sqm', 'label' => 'Ümumi sahə, m²', 'type' => 'number', 'options' => null, 'required' => true, 'delegatable' => false],
            ['key' => 'design_area_sqm', 'label' => 'Dizayn-layihə üçün sahə, m²', 'type' => 'number', 'options' => null, 'required' => true, 'delegatable' => false,
                'help' => 'Bütün obyekti deyil, yalnız bir hissəsini rəsmiləşdirirsinizsə, buraya həmin sahəni yazın.'],
            ['key' => 'property_readiness', 'label' => 'Obyektin hazırlığı', 'type' => 'select',
                'options' => $opt([
                    'new_shell' => 'Təmirsiz yeni tikili', 'resale_repaired' => 'Cari təmirlə ikinci əl',
                    'resale_raw' => 'Təmirsiz ikinci əl', 'other' => 'Digər',
                ]),
                'required' => true, 'delegatable' => false],
            ['key' => 'demolition_needed', 'label' => 'Demontaj işləri lazımdır?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'property_readiness', 'operator' => 'not_equals', 'value' => 'new_shell']],
            ['key' => 'has_measurement_plan', 'label' => 'Obmer planı / BTİ planı var?', 'type' => 'select',
                'options' => $opt(['yes' => 'Var, əlavə edəcəm', 'no' => 'Yox, obmer lazımdır', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false,
                'help' => 'Obmer planı olmadıqda layihələndirmənin startından əvvəl obmer sifariş etməyi tövsiyə edirik.'],
            ['key' => 'measurement_plan_file', 'label' => 'Obmer planı faylı', 'type' => 'file', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'PDF, JPG və ya PNG.',
                'skip' => ['question' => 'has_measurement_plan', 'operator' => 'equals', 'value' => 'yes']],
            ['key' => 'building_year', 'label' => 'Tikinti ili', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'ceiling_height_raw_mm', 'label' => 'Tavan hündürlüyü (çılpaq), mm', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Bilmirsinizsə, keçin — obmerdə dəqiqləşdirəcəyik.'],
            ['key' => 'screed_thickness_mm', 'label' => 'Stəjka qalınlığı, mm', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Bilmirsinizsə, keçin — obmerdə dəqiqləşdirəcəyik.'],
            ['key' => 'bearing_walls_material', 'label' => 'Daşıyıcı divarların materialı və qalınlığı', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: monolit, 200 mm.'],
            ['key' => 'floor_slabs_system', 'label' => 'Örtük sistemi və qalınlığı', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: monolit, 200 mm.'],
            ['key' => 'utilities_available', 'label' => 'Obyektin mühəndislik şəbəkələri', 'type' => 'multiselect',
                'options' => $opt([
                    'gas' => 'Qazlaşdırma', 'city_sewer' => 'Şəhər kanalizasiyası',
                    'private_sewer' => 'Fərdi kanalizasiya', 'water' => 'Su təchizatı',
                ]),
                'required' => true, 'delegatable' => false],
            ['key' => 'premises_purpose', 'label' => 'Mənzilin təyinatı', 'type' => 'select',
                'options' => $opt([
                    'permanent' => 'Daimi yaşayış', 'seasonal' => 'Mövsümi yaşayış (bağ evi)',
                    'commercial' => 'Kommersiya', 'mixed' => 'Qarışıq',
                ]),
                'required' => true, 'delegatable' => false],
            ['key' => 'windows_change', 'label' => 'Pəncərələr dəyişdiriləcək?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
            ['key' => 'windowsills_change', 'label' => 'Pəncərə lövhələri dəyişdiriləcək?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
            ['key' => 'freight_elevator', 'label' => 'Yük lifti var?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'object_type', 'operator' => 'equals', 'value' => 'apartment']],
            ['key' => 'staircase_width', 'label' => 'Pilləkən meydançasının eni', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: 1,2 m.',
                'skip' => ['question' => 'object_type', 'operator' => 'equals', 'value' => 'apartment']],
            ['key' => 'large_materials_turn', 'label' => 'İrigabaritli materialları döndərmək mümkündür?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false],
            ['key' => 'materials_delivery_comment', 'label' => 'Materialların qaldırılması üzrə şərh', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Mərtəbə, lift, dar keçidlər, məhdudiyyətlər.'],
            ['key' => 'furniture_heights', 'label' => 'Mebel hündürlükləri — standartdan fərqli istəkləriniz', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Standartlar: mətbəx dəzgahı 900 mm · unitaz 400 mm · lavabo 850 mm · iş masası 750 mm · tropik duş 2100 mm. Fərqli istəyiniz varsa qeyd edin.'],
        ],
    ],

    // ══════════════════ BÖLMƏ 3 — FORMAT VƏ BÜDCƏ (Part 9.3, NEW) ══════════════════
    [
        'key' => 'format_budget',
        'name' => ['az' => 'Format və büdcə', 'ru' => 'Формат и бюджет', 'en' => 'Scope & budget'],
        'icon' => 'banknotes',
        'estimated_minutes' => 2,
        'questions' => [
            ['key' => 'cooperation_scope', 'label' => 'Əməkdaşlıq formatı', 'type' => 'select',
                'options' => $opt([
                    'design_only' => 'Yalnız dizayn-layihə',
                    'design_procurement' => 'Dizayn + komplektasiya',
                    'design_supervision' => 'Dizayn + müəllif nəzarəti',
                    'turnkey' => 'Açar təhvili (təmirlə birlikdə)',
                    'rooms_only' => 'Yalnız ayrı otaqlar',
                ]),
                'required' => true, 'delegatable' => false],
            ['key' => 'project_budget_range', 'label' => 'Layihənin büdcəsi', 'type' => 'budget_range', 'options' => null, 'required' => true, 'delegatable' => false,
                'help' => 'Təxmini diapazon kifayətdir — bu, sizin büdcənizdə real həllər təklif etməyə kömək edir.'],
            ['key' => 'budget_includes', 'label' => 'Büdcə nələri əhatə edir?', 'type' => 'multiselect',
                'options' => $opt([
                    'materials' => 'Materiallar', 'furniture' => 'Mebel', 'appliances' => 'Texnika',
                    'design_fee' => 'Dizaynerin işi', 'construction' => 'Təmir işləri',
                ]),
                'required' => false, 'delegatable' => false],
            ['key' => 'desired_start_date', 'label' => 'İstənilən başlama tarixi', 'type' => 'date', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'desired_completion_date', 'label' => 'İstənilən bitmə / köçmə tarixi', 'type' => 'date', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'timeline_flexibility', 'label' => 'Müddətlərin çevikliyi', 'type' => 'select',
                'options' => $opt(['strict' => 'Sərt müddət', 'small_buffer' => 'Kiçik ehtiyat var', 'flexible' => 'Müddətlər prinsipial deyil']),
                'required' => false, 'delegatable' => false],
        ],
    ],

    // ══════════════════ BÖLMƏ 4 — ESTETİKA (Part 9.4) ══════════════════
    [
        'key' => 'aesthetics',
        'name' => ['az' => 'Estetika', 'ru' => 'Эстетика', 'en' => 'Aesthetics'],
        'icon' => 'sparkles',
        'estimated_minutes' => 6,
        'questions' => [
            ['key' => 'unified_style', 'label' => 'Bütün evdə vahid üslub olsun?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'per_room' => 'Xeyr, hər otaq özününkü', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'style_preferences', 'label' => 'Sizə yaxın olan üslublar', 'type' => 'multiselect',
                'options' => $opt([
                    'neoclassic' => 'Neoklassika', 'artdeco' => 'Ar-deko', 'eclectic' => 'Eklektika',
                    'minimalism' => 'Minimalizm', 'eco' => 'Eko-üslub', 'japandi' => 'Japandi',
                    'scandi' => 'Skandinav', 'industrial' => 'Sənaye', 'ethnic' => 'Etnik', 'chalet' => 'Şale',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'custom_style_reference', 'label' => 'Öz referanslarınız (link və ya təsvir)', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false],
            // Part 9.4: file_upload / url — the upload half of the pair.
            ['key' => 'custom_style_reference_file', 'label' => 'Referans foto yükləyin', 'type' => 'file', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'PDF, JPG və ya PNG.'],
            ['key' => 'open_to_unconventional', 'label' => 'Qeyri-standart həllərə hazırsınız?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'concept_base', 'label' => 'Konseptual əsas', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: «isti Skandinav evinin atmosferi».'],
            ['key' => 'style_extra_notes', 'label' => 'Üslub üzrə əlavə istəklər', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Nə nəzərə alınmalı, nədən qaçınmalı.'],
            ['key' => 'favorite_item_keep', 'label' => 'Yeni interyerə köçürüləcək sevimli əşya', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false],
            // Part 9.4: text + file_upload — the photo half of the pair.
            ['key' => 'favorite_item_photo', 'label' => 'Sevimli əşyanın fotosu', 'type' => 'file', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'JPG, PNG və ya PDF.'],
            ['key' => 'color_palette_visual', 'label' => 'Rəng palitrası', 'type' => 'color_swatch',
                'options' => [
                    'base_max' => 4,
                    'accent_max' => 2,
                    'swatches' => [
                        '#F7F5F2', '#EDE8E0', '#DFD6C7', '#CFC2AC', '#B8A78D', '#9C8B70', '#8A7A63', '#6E6250',
                        '#E9E9E6', '#CFD3CE', '#A9B2A6', '#7E8C7A', '#5A6B57', '#3C4A3A', '#2B2B2B', '#111111',
                        '#F3E7E4', '#E2C7C0', '#C89A90', '#A66E62', '#7C4A3E', '#DCE4EC', '#A9BED2', '#6D8AA6',
                    ],
                ],
                'required' => false, 'delegatable' => true,
                'help' => 'Sizə vizual olaraq yaxın olan tonları seçin: fon üçün 4-ə qədər, akcent üçün 2-yə qədər.'],
            ['key' => 'color_notes_freeform', 'label' => 'Öz sözlərinizlə rəng istəkləri', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'colors_to_avoid', 'label' => 'İstifadə edilməyəcək rənglər', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'interior_character', 'label' => 'İnteryerin xarakteri', 'type' => 'select',
                'options' => $opt(['contrast' => 'Kontrast, parlaq akcentlər', 'neutral' => 'Neytral pastel', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'color_temperature', 'label' => 'Tonların temperaturu', 'type' => 'select',
                'options' => $opt(['warm' => 'İsti', 'cold' => 'Soyuq', 'neutral' => 'Neytral', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'metal_preferences', 'label' => 'Metallar', 'type' => 'multiselect',
                'options' => $opt([
                    'gold' => 'Qızıl', 'copper' => 'Mis', 'silver' => 'Gümüş', 'iron' => 'Dəmir',
                    'brass' => 'Latun', 'chrome' => 'Xrom', 'steel' => 'Paslanmayan polad',
                    'aluminium' => 'Alüminium', 'bronze' => 'Bronza', 'matte_gold' => 'Mat qızıl',
                    'aged' => 'Köhnəlmiş metallar', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'surface_color_walls', 'label' => 'Divarların rəngi', 'type' => 'select',
                'options' => $opt(['light' => 'Açıq', 'dark' => 'Tünd', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'surface_color_floor', 'label' => 'Döşəmənin rəngi', 'type' => 'select',
                'options' => $opt(['light' => 'Açıq', 'dark' => 'Tünd', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'surface_color_ceiling', 'label' => 'Tavanın rəngi', 'type' => 'select',
                'options' => $opt(['light' => 'Açıq', 'dark' => 'Tünd', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
        ],
    ],

    // ══════════════════ BÖLMƏ 5 — ÖRTÜK MATERİALLARI (Part 9.5) ══════════════════
    [
        'key' => 'finish_materials',
        'name' => ['az' => 'Örtük materialları', 'ru' => 'Отделочные материалы', 'en' => 'Finish materials'],
        'icon' => 'swatch',
        'estimated_minutes' => 4,
        'questions' => [
            ['key' => 'finishes_full_override', 'label' => 'Bu bölməni tamamilə dizaynerin ixtiyarına buraxıram', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Seçsəniz, bölmənin qalan sualları gizlədilir.'],
            ['key' => 'finishes_priorities', 'label' => 'Materiallarda prioritetləriniz', 'type' => 'multiselect',
                'options' => $opt([
                    'price' => 'Qiymət', 'quality' => 'Keyfiyyət', 'eco' => 'Ekoloji təmizlik',
                    'durability' => 'Davamlılıq', 'practicality' => 'Praktiklik', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'wall_materials', 'label' => 'Divar materialları', 'type' => 'multiselect',
                'options' => $opt([
                    'wallpaper' => 'Divar kağızı', 'paint' => 'Boya', 'plaster' => 'Dekorativ suvaq',
                    'concrete' => 'Beton', 'stone' => 'Təbii daş', 'brick' => 'Kərpic', 'tile' => 'Plitka',
                    'panel3d' => '3D-panel', 'wood' => 'Ağac panel', 'textile' => 'Tekstil', 'mirror' => 'Güzgü',
                    'micro_cement' => 'Mikrosement', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'wall_materials_comment', 'label' => 'Divar materialları üzrə şərh (harada, hansı otaqda)', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'wall_materials', 'operator' => 'filled', 'value' => null]],
            ['key' => 'skirting_type', 'label' => 'Plintus', 'type' => 'select',
                'options' => $opt([
                    'surface' => 'Üzəri', 'hidden' => 'Gizli', 'shadow' => 'Kölgəli profil',
                    'lit' => 'İşıqlandırmalı', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'floor_materials', 'label' => 'Döşəmə materialları', 'type' => 'multiselect',
                'options' => $opt([
                    'parquet' => 'Parket', 'engineered' => 'Mühəndis lövhəsi', 'laminate' => 'Laminat',
                    'tile' => 'Keramoqranit / plitka', 'stone' => 'Təbii daş', 'vinyl' => 'Kvars-vinil',
                    'carpet' => 'Xalça örtüyü', 'micro_cement' => 'Mikrosement', 'self_leveling' => 'Nailənən döşəmə',
                    'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'ceiling_materials', 'label' => 'Tavan materialları', 'type' => 'multiselect',
                'options' => $opt([
                    'paint' => 'Boyalı', 'stretch_matte' => 'Dartma (mat)', 'stretch_gloss' => 'Dartma (parlaq)',
                    'gypsum' => 'Gips karton konstruksiyalar', 'wood' => 'Ağac', 'slat' => 'Reyka',
                    'acoustic' => 'Akustik panel', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'multilevel_ceiling_ok', 'label' => 'Çoxsəviyyəli tavanlara münasibətiniz müsbətdir?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'door_height', 'label' => 'Qapıların hündürlüyü', 'type' => 'select',
                'options' => $opt(['standard' => 'Standart (2000–2100 mm)', 'tall' => 'Hündür (2300+ mm)', 'to_ceiling' => 'Tavana qədər', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'door_mounting', 'label' => 'Qapıların montajı', 'type' => 'select',
                'options' => $opt(['standard' => 'Adi (nalişnikli)', 'hidden' => 'Gizli qutu', 'sliding' => 'Sürüşən', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'door_material', 'label' => 'Qapıların materialı', 'type' => 'multiselect',
                'options' => $opt(['veneer' => 'Şpon', 'paint' => 'Boyalı', 'glass' => 'Şüşə', 'solid_wood' => 'Massiv', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'door_locks_location', 'label' => 'Kilidlər hansı qapılarda lazımdır?', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'materials_to_avoid', 'label' => 'İstifadəsini istəmədiyiniz materiallar', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'finishes_full_override', 'operator' => 'not_equals', 'value' => '1']],
        ],
    ],

    // ══════════════════ BÖLMƏ 6 — İŞIQLANDIRMA (Part 9.6) ══════════════════
    [
        'key' => 'lighting',
        'name' => ['az' => 'İşıqlandırma', 'ru' => 'Освещение', 'en' => 'Lighting'],
        'icon' => 'light-bulb',
        'estimated_minutes' => 4,
        'questions' => [
            ['key' => 'lighting_full_override', 'label' => 'Bu bölməni tamamilə dizaynerin ixtiyarına buraxıram', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'light_temperature', 'label' => 'İşığın rəng temperaturu', 'type' => 'select',
                'options' => $opt([
                    'warm' => 'İsti (2700–3000 K)', 'neutral' => 'Neytral (3500–4000 K)',
                    'cold' => 'Soyuq (5000 K)', 'mixed' => 'Ssenariyə görə qarışıq', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'help' => '2700 K — şam işığı kimi isti; 4000 K — gündüz işığına yaxın; 5000 K — soyuq, ofis işığı.',
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'ceiling_lighting_types', 'label' => 'Tavan işıqlandırması', 'type' => 'multiselect',
                'options' => $opt([
                    'chandelier' => 'Çilçıraq', 'spots' => 'Nöqtəvi svetilniklər', 'track' => 'Trek sistemi',
                    'magnetic' => 'Maqnit sistemi', 'led_line' => 'LED xətt', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'wall_lighting_types', 'label' => 'Divar işıqlandırması', 'type' => 'multiselect',
                'options' => $opt(['bra' => 'Bra', 'wall_wash' => 'Divar işıqlandırması', 'picture_light' => 'Rəsm işıqları', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'floor_lighting_types', 'label' => 'Döşəmə / mobil işıqlandırma', 'type' => 'multiselect',
                'options' => $opt(['floor_lamp' => 'Torşer', 'table_lamp' => 'Stolüstü lampa', 'step_lights' => 'Pilləkən işıqları', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'decorative_lighting_types', 'label' => 'Dekorativ işıqlandırma', 'type' => 'multiselect',
                'options' => $opt(['niche' => 'Nişlərin işıqlandırılması', 'shelves' => 'Rəflərin işıqlandırılması', 'perimeter' => 'Perimetr LED', 'facade' => 'Fasad / bağ işıqlandırması', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'dimmers_needed', 'label' => 'Dimmerlər (işığın gücünün tənzimlənməsi) lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'night_lighting_sensors', 'label' => 'Gecə işıqlandırması (hərəkət sensorları) lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'night_lighting_location', 'label' => 'Gecə işıqlandırması harada?', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'night_lighting_sensors', 'operator' => 'equals', 'value' => '1']],
            ['key' => 'curtains_type', 'label' => 'Pərdələr', 'type' => 'select',
                'options' => $opt([
                    'classic' => 'Klassik pərdələr', 'roller' => 'Rulon pərdələr', 'roman' => 'Roma pərdələri',
                    'blinds' => 'Jalüzlər', 'none' => 'Pərdəsiz', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'lighting_full_override', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'curtains_blackout_location', 'label' => 'Tam qaranlıqlaşdırma hansı otaqlarda lazımdır?', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'curtains_type', 'operator' => 'not_equals', 'value' => 'none']],
        ],
    ],

    // ══════════════════ BÖLMƏ 7 — MÜHƏNDİSLİK VƏ AĞILLI EV (Part 9.7, D5) ══════════════════
    [
        'key' => 'engineering',
        'name' => ['az' => 'Mühəndislik və ağıllı ev', 'ru' => 'Инженерия и умный дом', 'en' => 'Engineering & smart home'],
        'icon' => 'wrench-screwdriver',
        'estimated_minutes' => 4,
        'questions' => [
            ['key' => 'replanning_needed', 'label' => 'Yenidənqurma (planlaşdırmanın dəyişdirilməsi) planlaşdırılır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Bəli olduqda istədiyiniz yeni planın eskizini «Obyekt» bölməsində fayl kimi əlavə edə bilərsiniz.'],
            ['key' => 'balcony_merge_with_room', 'label' => 'Balkon/loggiyanı otaqla birləşdirmək istəyirsiniz?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'room_inventory', 'operator' => 'has_room', 'value' => 'balcony']],
            ['key' => 'balcony_insulate', 'label' => 'Balkon/loggiya istiləşdiriləcək?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'room_inventory', 'operator' => 'has_room', 'value' => 'balcony']],
            ['key' => 'underfloor_heating', 'label' => 'İsti döşəmə', 'type' => 'select',
                'options' => $opt([
                    'primary' => 'Əsas istilik mənbəyi kimi', 'additional' => 'Əlavə kimi',
                    'tile_only' => 'Yalnız plitka olan yerdə', 'none' => 'Lazım deyil', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'radiators_replace', 'label' => 'Radiatorlar / konvektorlar', 'type' => 'multiselect',
                'options' => $opt(['radiators' => 'Radiatorların dəyişdirilməsi', 'convectors' => 'Konvektorların dəyişdirilməsi', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'heating_control_type', 'label' => 'İstilik idarəetmə cihazının tipi', 'type' => 'select',
                'options' => $opt(['thermo_head' => 'Termobaşlıqla', 'wall_thermostat' => 'Divar terroregulyatoru ilə', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'underfloor_heating', 'operator' => 'not_equals', 'value' => 'none']],
            ['key' => 'climate_equipment', 'label' => 'İqlim avadanlığı', 'type' => 'multiselect',
                'options' => $opt(['ac' => 'Kondisioner', 'ventilation' => 'Məcburi ventilyasiya', 'humidifier' => 'Nəmləndirmə', 'purifier' => 'Hava təmizləmə', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'water_heater_type', 'label' => 'Su qızdırıcısı', 'type' => 'select',
                'options' => $opt(['storage' => 'Yığıcı (boyler)', 'flow' => 'Axar', 'per_riser' => 'Hər stoyaka ayrıca', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'water_heater_volume_l', 'label' => 'Yığıcı su qızdırıcısının həcmi, litr', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: 80.',
                'skip' => ['question' => 'water_heater_type', 'operator' => 'equals', 'value' => 'storage']],
            ['key' => 'water_filtration', 'label' => 'Su təmizləmə / filtrasiya lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
            ['key' => 'ethernet_outlets_location', 'label' => 'İnternet rozetkaları harada lazımdır?', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: kabinet, qonaq otağı.'],
            ['key' => 'security_equipment', 'label' => 'Təhlükəsizlik avadanlığı', 'type' => 'multiselect',
                'options' => $opt([
                    'video_intercom' => 'Video domofon', 'intercom_tv' => 'Domofonun TV-yə çıxışı',
                    'alarm' => 'Mühafizə siqnalizasiyası', 'fire_alarm' => 'Yanğın siqnalizasiyası',
                    'motion' => 'Hərəkət sensorları', 'cctv' => 'Videomüşahidə', 'designer' => 'Dizaynerin ixtiyarına',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'cctv_locations', 'label' => 'Müşahidə kameraları harada?', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: giriş, qaraj, sahə.',
                'skip' => ['question' => 'security_equipment', 'operator' => 'equals', 'value' => 'cctv']],
            ['key' => 'smart_home_needed', 'label' => 'Ağıllı ev sistemi', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'designer' => 'Dizaynerin ixtiyarına']),
                'required' => false, 'delegatable' => true],
            ['key' => 'electrics_note', 'label' => 'Elektrik üzrə xüsusi tələblər', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Rozetkaların yeri, xüsusi avadanlıq üçün güc və s.'],
        ],
    ],

    // ══════════════════ BÖLMƏ 8 — OTAQLAR: dinamik tərkib (Part 8.3, 9.8) ══════════════════
    [
        'key' => 'rooms_hub',
        'name' => ['az' => 'Otaqların tərkibi', 'ru' => 'Состав помещений', 'en' => 'Rooms'],
        'icon' => 'squares-2x2',
        'estimated_minutes' => 2,
        'questions' => [
            ['key' => 'room_inventory', 'label' => 'Obyektdə hansı otaqlar var?', 'type' => 'room_inventory',
                'options' => $opt([
                    'hallway' => 'Dəhliz / hol',
                    'kitchen' => 'Mətbəx',
                    'dining' => 'Yeməkxana',
                    'living' => 'Qonaq otağı',
                    'bathroom' => 'Ümumi sanuzel',
                    'bedroom' => 'Əsas yataq otağı',
                    'wardrobe_master' => 'Yataq otağı yanında qarderob',
                    'bathroom_master' => 'Yataq otağı yanında sanuzel',
                    'kids' => 'Uşaq yataq otağı',
                    'wardrobe_kids' => 'Uşaq otağı yanında qarderob',
                    'bathroom_kids' => 'Uşaq otağı yanında sanuzel',
                    'study' => 'Kabinet',
                    'guest_bedroom' => 'Qonaq yataq otağı',
                    'laundry' => 'Paltaryuyan otaq',
                    'tech_kitchen' => 'Texniki mətbəx',
                    'staircase' => 'Pilləkən',
                    'balcony' => 'Balkon / loggiya',
                    'other_room' => 'Digər otaq',
                ]),
                'required' => true, 'delegatable' => false,
                'help' => 'Yalnız işarələdiyiniz otaqlar üzrə suallar açılacaq. Eyni tipdən bir neçə otaq varsa, sayını göstərin.'],
            ['key' => 'rooms_note', 'label' => 'Otaqlar barədə ümumi qeydləriniz', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false],
        ],
    ],

    // ── Otaq bölmələri (yalnız room_inventory-də seçilənlər göstərilir) ──

    $roomSection('room_hallway', 'hallway', ['az' => 'Dəhliz / hol', 'ru' => 'Прихожая', 'en' => 'Hallway'], [
        ['key' => 'entryway_wardrobe_type', 'label' => 'Qarderobun tipi', 'type' => 'select',
            'options' => $opt(['surface' => 'Üzəri', 'sliding' => 'Kupe', 'hanging' => 'Asma', 'built_in' => 'Quraşdırılmış', 'open' => 'Açıq qarderob', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'entryway_shoe_storage', 'label' => 'Ayaqqabı saxlama', 'type' => 'select',
            'options' => $opt(['closed' => 'Qapalı', 'semi' => 'Qismən qapalı', 'open' => 'Açıq rəflər', 'extra' => 'Əlavə həll', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'entryway_shoe_count_approx', 'label' => 'Təxminən neçə cüt ayaqqabı saxlanılacaq?', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'entryway_extra', 'label' => 'Əlavə elementlər', 'type' => 'multiselect',
            'options' => $opt([
                'console' => 'Konsol', 'bench' => 'Banketka', 'sofa' => 'Divan', 'utility' => 'Təsərrüfat şkafı',
                'laundry_zone' => 'Paltaryuyan zona', 'tree_spot' => 'Yeni il ağacı üçün yer', 'designer' => 'Dizaynerin ixtiyarına',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'entryway_mirror', 'label' => 'Güzgü', 'type' => 'select',
            'options' => $opt(['full' => 'Tam boy', 'over_console' => 'Konsol üzərində', 'on_door' => 'Qapıda', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'entryway_tech', 'label' => 'Texnika və avadanlıq', 'type' => 'multiselect',
            'options' => $opt([
                'video_intercom' => 'Video domofon', 'camera_panel' => 'Videokamera / panel', 'router' => 'Router',
                'gateway' => 'Ağıllı ev şlüzü', 'charging' => 'Cihazlar üçün enerji doldurma',
                'robot_vacuum' => 'Robot-tozsoran', 'manifold' => 'Kollektor qovşağı', 'other' => 'Digər',
            ]),
            'required' => false, 'delegatable' => true],
        $roomNotes('entryway'),
    ]),

    $roomSection('room_kitchen', 'kitchen', ['az' => 'Mətbəx', 'ru' => 'Кухня', 'en' => 'Kitchen'], [
        ['key' => 'kitchen_cooking_freq', 'label' => 'Nə qədər tez-tez yemək bişirilir?', 'type' => 'select',
            'options' => $opt(['daily' => 'Hər gün', 'often' => 'Tez-tez', 'rare' => 'Nadir hallarda']),
            'required' => false, 'delegatable' => false],
        ['key' => 'kitchen_layout', 'label' => 'Mətbəxin planlaşdırılması', 'type' => 'select',
            'options' => $opt(['linear' => 'Xətti', 'l_shape' => 'L-formalı', 'u_shape' => 'U-formalı', 'island' => 'Ada ilə', 'peninsula' => 'Yarımada ilə', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_appliances', 'label' => 'Lazım olan texnika', 'type' => 'multiselect',
            'options' => $opt([
                'hob' => 'Bişirmə paneli', 'oven' => 'Soba', 'microwave' => 'Mikrodalğalı', 'dishwasher' => 'Qabyuyan',
                'fridge_big' => 'Böyük soyuducu', 'freezer' => 'Ayrıca dondurucu', 'coffee' => 'Qəhvə maşını',
                'steamer' => 'Buxar sobası', 'wine' => 'Şərab şkafı', 'designer' => 'Dizaynerin ixtiyarına',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_upper_cabinets', 'label' => 'Yuxarı şkaflar', 'type' => 'select',
            'options' => $opt(['to_ceiling' => 'Tavana qədər antresol', 'standard' => 'Standart hündürlük', 'none' => 'Yuxarı şkafsız', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomStorage('kitchen'),
        $roomNotes('kitchen'),
    ]),

    $roomSection('room_dining', 'dining', ['az' => 'Yeməkxana', 'ru' => 'Столовая', 'en' => 'Dining room'], [
        ['key' => 'dining_seats', 'label' => 'Neçə nəfərlik masa lazımdır?', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'dining_table_shape', 'label' => 'Masanın forması', 'type' => 'select',
            'options' => $opt(['rect' => 'Düzbucaqlı', 'round' => 'Dairəvi', 'oval' => 'Oval', 'extendable' => 'Açılan', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomStorage('dining'), $roomDecor('dining'), $roomGreenery('dining'), $roomNotes('dining'),
    ]),

    $roomSection('room_living', 'living', ['az' => 'Qonaq otağı', 'ru' => 'Гостиная', 'en' => 'Living room'], [
        ['key' => 'living_seats', 'label' => 'Neçə oturacaq yeri lazımdır?', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'living_tv_zone', 'label' => 'TV / media zonası lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'living_fireplace', 'label' => 'Kamin', 'type' => 'select',
            'options' => $opt(['real' => 'Həqiqi', 'bio' => 'Bio-kamin', 'electric' => 'Elektrik', 'none' => 'Lazım deyil', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_sleeping_guest', 'label' => 'Qonaq üçün yataq yeri (açılan divan) lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
        $roomStorage('living'), $roomDecor('living'), $roomGreenery('living'), $roomNotes('living'),
    ]),

    $roomSection('room_bathroom', 'bathroom', ['az' => 'Ümumi sanuzel', 'ru' => 'Общий санузел', 'en' => 'Common bathroom'], [
        ['key' => 'bathroom_bath_or_shower', 'label' => 'Vanna yoxsa duş?', 'type' => 'select',
            'options' => $opt(['bath' => 'Vanna', 'shower' => 'Duş', 'both' => 'Hər ikisi', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'bathroom_laundry', 'label' => 'Paltaryuyan bu otaqda yerləşəcək?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'bathroom_equipment', 'label' => 'Avadanlıq', 'type' => 'multiselect',
            'options' => $opt(['hygienic_shower' => 'Gigiyenik duş', 'bidet' => 'Bide', 'double_sink' => 'İki lavabo', 'heated_towel' => 'Qızdırıcılı dəsmal asqısı', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomStorage('bathroom'), $roomNotes('bathroom'),
    ]),

    $roomSection('room_bedroom', 'bedroom', ['az' => 'Əsas yataq otağı', 'ru' => 'Главная спальня', 'en' => 'Master bedroom'], [
        ['key' => 'bedroom_bed_size', 'label' => 'Çarpayının eni', 'type' => 'select',
            'options' => $opt(['140' => '140 sm', '160' => '160 sm', '180' => '180 sm', '200' => '200 sm', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_tv', 'label' => 'Yataq otağında TV lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_workspace', 'label' => 'Yataq otağında iş / tualet masası lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
        $roomStorage('bedroom'), $roomDecor('bedroom'), $roomGreenery('bedroom'), $roomNotes('bedroom'),
    ]),

    $roomSection('room_wardrobe_master', 'wardrobe_master', ['az' => 'Qarderob (yataq otağı)', 'ru' => 'Гардеробная при спальне', 'en' => 'Master wardrobe'], [
        ['key' => 'wardrobe_master_systems', 'label' => 'Saxlama sistemləri', 'type' => 'multiselect',
            'options' => $opt(['pantograph' => 'Pantoqraf', 'trousers' => 'Şalvar asqısı', 'drawers' => 'Siyirmələr', 'shoe_racks' => 'Ayaqqabı rəfləri', 'safe' => 'Seyf', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'wardrobe_master_island', 'label' => 'Qarderobda ada / komod lazımdır?', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
        $roomNotes('wardrobe_master'),
    ]),

    $roomSection('room_bathroom_master', 'bathroom_master', ['az' => 'Sanuzel (yataq otağı)', 'ru' => 'Санузел при спальне', 'en' => 'Master bathroom'], [
        ['key' => 'bathroom_master_bath_or_shower', 'label' => 'Vanna yoxsa duş?', 'type' => 'select',
            'options' => $opt(['bath' => 'Vanna', 'shower' => 'Duş', 'both' => 'Hər ikisi', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'bathroom_master_equipment', 'label' => 'Avadanlıq', 'type' => 'multiselect',
            'options' => $opt(['hygienic_shower' => 'Gigiyenik duş', 'bidet' => 'Bide', 'double_sink' => 'İki lavabo', 'tropical' => 'Tropik duş', 'steam' => 'Hamam / buxar', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomStorage('bathroom_master'), $roomNotes('bathroom_master'),
    ]),

    $roomSection('room_kids', 'kids', ['az' => 'Uşaq otağı', 'ru' => 'Детская', 'en' => 'Kids room'], [
        // Screen 08b: every room field is Optional.
        ['key' => 'kids_age_gender', 'label' => 'Uşağın yaşı və cinsi', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'kids_zones', 'label' => 'Lazım olan zonalar', 'type' => 'multiselect',
            'options' => $opt(['sleep' => 'Yuxu', 'study' => 'Dərs', 'play' => 'Oyun', 'sport' => 'İdman küncü', 'creative' => 'Yaradıcılıq', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'kids_grow_plan', 'label' => 'Otaq uşaq böyüdükcə dəyişməlidir? (5+ il)', 'type' => 'boolean', 'options' => null, 'required' => false, 'delegatable' => true],
        $roomStorage('kids'), $roomDecor('kids'), $roomNotes('kids'),
    ]),

    $roomSection('room_wardrobe_kids', 'wardrobe_kids', ['az' => 'Qarderob (uşaq otağı)', 'ru' => 'Гардеробная при детской', 'en' => 'Kids wardrobe'], [
        ['key' => 'wardrobe_kids_systems', 'label' => 'Saxlama sistemləri', 'type' => 'multiselect',
            'options' => $opt(['low_rails' => 'Alçaq asqılar', 'drawers' => 'Siyirmələr', 'toys' => 'Oyuncaq saxlama', 'open_shelves' => 'Açıq rəflər', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomNotes('wardrobe_kids'),
    ]),

    $roomSection('room_bathroom_kids', 'bathroom_kids', ['az' => 'Sanuzel (uşaq otağı)', 'ru' => 'Санузел при детской', 'en' => 'Kids bathroom'], [
        ['key' => 'bathroom_kids_bath_or_shower', 'label' => 'Vanna yoxsa duş?', 'type' => 'select',
            'options' => $opt(['bath' => 'Vanna', 'shower' => 'Duş', 'both' => 'Hər ikisi', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'bathroom_kids_safety', 'label' => 'Uşaq təhlükəsizliyi elementləri', 'type' => 'multiselect',
            'options' => $opt(['anti_slip' => 'Sürüşməyən örtük', 'thermostat' => 'Termostatlı qarışdırıcı', 'step' => 'Pilləkən-kətil', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomNotes('bathroom_kids'),
    ]),

    $roomSection('room_study', 'study', ['az' => 'Kabinet', 'ru' => 'Кабинет', 'en' => 'Study'], [
        ['key' => 'study_users', 'label' => 'Kabinetdən neçə nəfər eyni vaxtda istifadə edəcək?', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'study_needs', 'label' => 'Funksional tələblər', 'type' => 'multiselect',
            'options' => $opt([
                'video_calls' => 'Video zənglər üçün fon', 'library' => 'Kitab rəfləri', 'printer' => 'Printer / texnika',
                'safe' => 'Seyf', 'guest_bed' => 'Qonaq üçün yataq yeri', 'soundproof' => 'Səs izolyasiyası', 'designer' => 'Dizaynerin ixtiyarına',
            ]),
            'required' => false, 'delegatable' => true],
        $roomStorage('study'), $roomDecor('study'), $roomGreenery('study'), $roomNotes('study'),
    ]),

    $roomSection('room_guest_bedroom', 'guest_bedroom', ['az' => 'Qonaq yataq otağı', 'ru' => 'Гостевая спальня', 'en' => 'Guest bedroom'], [
        ['key' => 'guest_bedroom_frequency', 'label' => 'Qonaqlar nə qədər tez-tez qalır?', 'type' => 'select',
            'options' => $opt(['often' => 'Tez-tez', 'sometimes' => 'Bəzən', 'rare' => 'Nadir hallarda']),
            'required' => false, 'delegatable' => false],
        ['key' => 'guest_bedroom_dual_use', 'label' => 'Otaq ikinci funksiyada da istifadə olunacaq?', 'type' => 'multiselect',
            'options' => $opt(['study' => 'Kabinet', 'gym' => 'İdman', 'storage' => 'Saxlama', 'none' => 'Xeyr, yalnız qonaq üçün', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomStorage('guest_bedroom'), $roomNotes('guest_bedroom'),
    ]),

    $roomSection('room_laundry', 'laundry', ['az' => 'Paltaryuyan otaq', 'ru' => 'Постирочная', 'en' => 'Laundry room'], [
        ['key' => 'laundry_equipment', 'label' => 'Avadanlıq', 'type' => 'multiselect',
            'options' => $opt([
                'washer' => 'Paltaryuyan', 'dryer' => 'Qurutma maşını', 'ironing' => 'Ütü lövhəsi',
                'sink' => 'Lavabo', 'drying_rack' => 'Qurutma asqısı', 'boiler' => 'Boyler', 'designer' => 'Dizaynerin ixtiyarına',
            ]),
            'required' => false, 'delegatable' => true],
        $roomStorage('laundry'), $roomNotes('laundry'),
    ]),

    $roomSection('room_tech_kitchen', 'tech_kitchen', ['az' => 'Texniki mətbəx', 'ru' => 'Техническая кухня', 'en' => 'Prep kitchen'], [
        ['key' => 'tech_kitchen_purpose', 'label' => 'İstifadə xarakteri', 'type' => 'select',
            'options' => $opt(['daily' => 'Gündəlik istifadə', 'events' => 'Müvəqqəti istifadə (tədbirlər)']),
            'required' => false, 'delegatable' => false],
        ['key' => 'tech_kitchen_staff_count', 'label' => 'Eyni vaxtda neçə nəfər işləyəcək?', 'type' => 'number', 'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'tech_kitchen_equipment_type', 'label' => 'Avadanlığın tipi', 'type' => 'select',
            'options' => $opt(['professional' => 'Peşəkar', 'domestic' => 'Məişət', 'other' => 'Digər', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_furniture', 'label' => 'Mebel həlli', 'type' => 'select',
            'options' => $opt(['to_ceiling' => 'Tavana qədər antresol', 'no_upper' => 'Yuxarı şkafsız', 'standard' => 'Adi mətbəxə analoji', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        $roomNotes('tech_kitchen'),
    ]),

    $roomSection('room_staircase', 'staircase', ['az' => 'Pilləkən', 'ru' => 'Лестница', 'en' => 'Staircase'], [
        ['key' => 'staircase_material', 'label' => 'Pilləkənin materialı', 'type' => 'multiselect',
            'options' => $opt(['wood' => 'Ağac', 'metal' => 'Metal', 'concrete' => 'Beton', 'stone' => 'Daş', 'glass' => 'Şüşə', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'staircase_railing', 'label' => 'Məhəccər', 'type' => 'select',
            'options' => $opt(['glass' => 'Şüşə', 'metal' => 'Metal', 'wood' => 'Ağac', 'cable' => 'Kabel', 'designer' => 'Dizaynerin ixtiyarına']),
            'required' => false, 'delegatable' => true],
        ['key' => 'staircase_under_use', 'label' => 'Pilləkənaltı sahə necə istifadə olunsun?', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => true],
        $roomNotes('staircase'),
    ]),

    $roomSection('room_balcony', 'balcony', ['az' => 'Balkon / loggiya', 'ru' => 'Балкон / лоджия', 'en' => 'Balcony'], [
        ['key' => 'balcony_function', 'label' => 'Balkonun funksiyası', 'type' => 'multiselect',
            'options' => $opt([
                'relax' => 'İstirahət zonası', 'workspace' => 'İş yeri', 'storage' => 'Saxlama',
                'laundry' => 'Paltaryuyan zona', 'greenery' => 'Yaşıllıq / oranjereya', 'designer' => 'Dizaynerin ixtiyarına',
            ]),
            'required' => false, 'delegatable' => true],
        $roomGreenery('balcony'), $roomNotes('balcony'),
    ]),

    $roomSection('room_other', 'other_room', ['az' => 'Digər otaq', 'ru' => 'Другое помещение', 'en' => 'Other room'], [
        ['key' => 'other_room_name', 'label' => 'Otağın adı / təyinatı', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'other_room_function', 'label' => 'Bu otaq necə istifadə olunacaq?', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false],
        $roomStorage('other_room'), $roomDecor('other_room'), $roomNotes('other_room'),
    ]),

    // ══════════════════ BÖLMƏ 9 — KOMPLEKTASİYA VƏ PODRATÇILAR (Part 9.9, D6) ══════════════════
    [
        'key' => 'procurement',
        'name' => ['az' => 'Komplektasiya və podratçılar', 'ru' => 'Комплектация и подрядчики', 'en' => 'Procurement & contractors'],
        'icon' => 'shopping-bag',
        'estimated_minutes' => 3,
        'questions' => [
            ['key' => 'brand_level_matrix', 'label' => 'Kateqoriyalar üzrə brend səviyyəsi', 'type' => 'matrix',
                'options' => [
                    'rows' => $opt([
                        'finishes' => 'Örtük materialları', 'furniture' => 'Mebel', 'lighting' => 'İşıqlandırma',
                        'plumbing' => 'Santexnika', 'doors' => 'Qapılar',
                    ]),
                    'columns' => $opt([
                        'budget' => 'Büdcəli', 'known_brand' => 'Tanınmış brend',
                        'exclusive' => 'Eksklüziv / sifarişlə', 'designer' => 'Dizaynerin ixtiyarına',
                    ]),
                ],
                'required' => false, 'delegatable' => true,
                'help' => 'Hər sətir üçün bir variant seçin.'],
            ['key' => 'preferred_brands_freeform', 'label' => 'Prioritet istehsalçılar / brendlər, əgər varsa', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'implementation_approach', 'label' => 'Layihənin reallaşdırılması', 'type' => 'select',
                'options' => $opt(['now' => 'Dərhal', 'later' => 'Təxirə salmaq', 'phased' => 'Mərhələli']),
                'required' => false, 'delegatable' => true],
            ['key' => 'contractor_matrix', 'label' => 'Podratçılar', 'type' => 'matrix',
                'options' => [
                    'rows' => $opt([
                        'crew' => 'Təmir briqadası', 'electrics' => 'Elektrik', 'plumbing' => 'Santexnika',
                        'hvac' => 'Kondisionerləşdirmə və ventilyasiya', 'furniture' => 'Mebel (istehsalı)',
                        'doors' => 'Qapılar', 'lighting' => 'İşıq (montaj)', 'decor' => 'Dekor / yaşıllaşdırma',
                        'other' => 'Digər',
                    ]),
                    'columns' => $opt([
                        'own' => 'Öz podratçısı var', 'none' => 'Yox', 'need_recommendation' => 'Dizaynerin tövsiyəsi lazımdır',
                    ]),
                ],
                'required' => false, 'delegatable' => true,
                'help' => 'Hər iş kateqoriyası üçün bir variant seçin — bu, əvvəlki 9 təkrarlanan sualı əvəz edir.'],
            ['key' => 'contractor_other_category', 'label' => '«Digər» kateqoriyasını dəqiqləşdirin', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'contractor_matrix', 'operator' => 'matrix_row_filled', 'value' => 'other']],
        ],
    ],

    // ══════════════════ BÖLMƏ 10 — FİNAL: İSTƏKLƏR VƏ KONTAKTLAR (Part 9.10) ══════════════════
    [
        'key' => 'contacts',
        'name' => ['az' => 'Final: istəklər və kontaktlar', 'ru' => 'Финал: пожелания и контакты', 'en' => 'Final: wishes & contacts'],
        'icon' => 'phone',
        'estimated_minutes' => 2,
        'questions' => [
            ['key' => 'special_requests', 'label' => 'Xüsusi şərtlər və istəklər', 'type' => 'textarea', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Dizaynerə vacib olan hər şey.'],
            ['key' => 'contact_full_name', 'label' => 'Ad və soyad', 'type' => 'text', 'options' => null, 'required' => true, 'delegatable' => false],
            ['key' => 'contact_phone', 'label' => 'Telefon', 'type' => 'text', 'options' => null, 'required' => true, 'delegatable' => false,
                'help' => 'Ölkə kodu ilə, məsələn +994 50 123 45 67.'],
            ['key' => 'contact_email', 'label' => 'Email', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'contact_time', 'label' => 'Əlaqə üçün rahat vaxt', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'pdpa_consent', 'label' => 'Şəxsi məlumatlarımın emalına razılıq verirəm', 'type' => 'consent', 'options' => null, 'required' => true, 'delegatable' => false,
                'help' => 'Cavablarınız yalnız layihənin hazırlanması üçün istifadə olunur və üçüncü tərəflərə verilmir. Razılıq olmadan brif göndərilə bilməz.'],
            ['key' => 'passport_details', 'label' => 'Pasport məlumatları (seriya və nömrə)', 'type' => 'text', 'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Yalnız müqavilə üçün lazımdır və sonra da göstərilə bilər — brifin göndərilməsini bloklamır.',
                'skip' => ['question' => 'pdpa_consent', 'operator' => 'equals', 'value' => '1']],
        ],
    ],
];
