<?php

/**
 * Brif sual bankı — Roomix «Премиум бриф» ilə BİR-BİR uyğunlaşdırılıb.
 *
 * Mənbə: docs/roomix-brief-parity.md (canlı saytdan çıxarılmış tam struktur —
 * hər bölmə, qrup, sual və variant orada rus dilində olduğu kimi yazılıb).
 * Dəyişiklik edəndə ƏVVƏLCƏ o sənədi yenilə, sonra buranı.
 *
 * Bölmələrin sırası (Roomix-dəki kimi 9 bölmə):
 *   1 Sizin haqqınızda → 2 Obyekt → 3 Komplektasiya → 4 Estetika →
 *   5 Örtük materialları → 6 İşıqlandırma → 7 Otaqlar (hub + otaq blokları) →
 *   8 Mühəndislik → 9 Kontaktlar
 *
 * ── Roomix-dən FƏRQLİ olan yeganə blok ──
 * «Komplektasiya» bölməsinin içində «Format və büdcə» qrupu var
 * (`cooperation_scope`, `property_readiness`, `project_budget_range`,
 * `desired_completion_date`). Roomix bu sualları brifdə soruşmur, amma Archi-də
 * onlar Quick Brief → Premium keçidinin və risk analizinin daşıyıcısıdır
 * (BriefService::switchTemplate/answerPriorities, BriefRiskDetector) — silinsə,
 * o mexanizmlər qırılır. Ona görə saxlanılır və ayrıca qrup kimi işarələnir.
 *
 * Sual formatı (assoc):
 *   key, label (string|array), type, options, required, delegatable, help, skip,
 *   group (bölmə daxilində alt başlıq), inspire (bax: aşağıda B patterni)
 *
 * type dəyərləri: text · textarea · number · select · multiselect · boolean ·
 *   date · image_select · image_multiselect · image_rating · std_or_custom ·
 *   repeater · matrix · color_swatch · budget_range · room_inventory ·
 *   consent · file
 *
 * ── Şəklin üç rolu ──
 *
 * A patterni — SEÇİMİN ÖZÜ ŞƏKİLDİR: `image_select` / `image_multiselect`.
 *   Roomix-də yalnız üslub kartları belədir. Şəkil yoxdursa kart neytral
 *   placeholder kimi görünür — quruluş yenə də doğrudur.
 *
 * B patterni — SEÇİM MƏTNDİR, ŞƏKİL İLHAM NÜMUNƏSİDİR: sualda `'inspire' => true`.
 *   Roomix-də divar / tavan / işıq / pərdə siyahılarında hər variantın yanında
 *   «Примеры» ikonu var; variant adi sətir kimi qalır.
 *
 * C patterni — ŞƏKİL ƏVƏZİNƏ RƏNG: metal çipləri və rəng kombinasiyaları
 *   Roomix-də də foto deyil, rəng sahəsidir (`colors` açarı) — fayl lazım deyil.
 *
 * Foto yolları variantın içində saxlanılır (`image_url` / `images`) və admin
 * panelindən (Sistem → Brif şəkilləri) yüklənir — bankda hardcode EDİLMİR.
 */
$opt = fn (array $pairs) => collect($pairs)
    ->map(fn ($label, $value) => ['value' => (string) $value, 'label' => ['az' => $label]])
    ->values()
    ->all();

/** Rəng çipli variantlar (metallar) — `value => [ad, hex…]`. */
$swatchOpt = fn (array $rows) => collect($rows)
    ->map(function (array $row, $value) {
        $label = array_shift($row);

        return ['value' => (string) $value, 'label' => ['az' => $label], 'colors' => $row];
    })
    ->values()
    ->all();

/**
 * Rəng kombinasiyası kartları (Roomix-də 27 ədəd). Palitralar rəng dəyərləridir,
 * ona görə Archi onları öz CSS zolaqları ilə qurur — foto lazım deyil. Admin
 * istəsə hər kartın üstünə şəkil yükləyə bilər (`image_url`).
 */
$paletteOpt = fn (array $palettes) => collect($palettes)
    ->map(fn (string $hexes, int $i) => [
        'value' => 'combo_'.($i + 1),
        'label' => ['az' => 'Kombinasiya '.($i + 1)],
        'colors' => array_map(fn ($h) => '#'.$h, explode(',', $hexes)),
        'image_url' => null,
    ])
    ->values()
    ->all();

$designer = 'Dizaynerin ixtiyarına';
$yesNoDesigner = ['yes' => 'Bəli', 'no' => 'Xeyr', 'designer' => $designer];

// ── Otaq bloklarının yenidən istifadə edilə bilən komponentləri ──

$roomNotes = fn (string $p) => [
    'key' => $p.'_notes', 'label' => 'Əlavə istəklər', 'type' => 'textarea',
    'options' => null, 'required' => false, 'delegatable' => false,
];

/** Roomix bir neçə otaqda eyni «TV diaqonalı» sualını təkrarlayır. */
$roomTvDiagonal = fn (string $p) => [
    'key' => $p.'_tv_diagonal', 'label' => 'TV-nin diaqonalı, düym', 'type' => 'number',
    'options' => null, 'required' => false, 'delegatable' => true,
    'help' => 'Dəqiq bilmirsinizsə «Dizaynerin ixtiyarına» qeyd edin.',
];

/**
 * Sanuzel bloku — Roomix-də ümumi sanuzel, yataq otağı yanındakı və uşaq
 * sanuzeli EYNİ sual dəstidir; fərq yalnız bloka qədər gələn əlavə suallardır.
 *
 * @param  array<int, array<string, mixed>>  $lead
 */
$bathroomBlock = fn (string $p, array $lead = []) => array_merge($lead, [
    ['key' => $p.'_type', 'label' => 'Sanuzelin tipi', 'type' => 'select',
        'options' => $opt(['combined' => 'Birləşmiş', 'separate' => 'Ayrı']),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_vanity', 'label' => 'Raqovina altı tumba', 'type' => 'select',
        'options' => $opt(['hanging' => 'Asma', 'floor' => 'Döşəmə üstü', 'designer' => $designer]),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_mirror', 'label' => 'Güzgü', 'type' => 'multiselect',
        'options' => $opt([
            'over_sink' => 'Raqovinanın üstündə', 'full_height' => 'Tam boy',
            'freestanding' => 'Ayrıca duran', 'lit' => 'İşıqlandırmalı', 'designer' => $designer,
        ]),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_cabinet', 'label' => 'Şkaf', 'type' => 'multiselect',
        'options' => $opt([
            'built_in' => 'Quraşdırılmış', 'freestanding' => 'Ayrıca duran',
            'open_shelf' => 'Açıq stellaj', 'hanging' => 'Asma', 'chest' => 'Komod', 'designer' => $designer,
        ]),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
        'options' => $opt([
            'vanity_table' => 'Tualet masası', 'pouf' => 'Puf', 'bench' => 'Skamya',
            'trash' => 'Zibil qabı', 'laundry_built_in' => 'Quraşdırılmış paltar səbəti',
            'laundry_free' => 'Ayrıca paltar səbəti',
        ]),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_towel_hooks', 'label' => 'Dəsmal asacaqlarının sayı', 'type' => 'number',
        'options' => null, 'required' => false, 'delegatable' => true],
    ['key' => $p.'_robe_hooks', 'label' => 'Xalat asacaqlarının sayı', 'type' => 'number',
        'options' => null, 'required' => false, 'delegatable' => true],
    ['key' => $p.'_fixtures', 'label' => 'Santexnika dəsti', 'type' => 'multiselect',
        'options' => $opt([
            'bath' => 'Vanna', 'shower' => 'Duş kabinası', 'sink' => 'Raqovina', 'wc' => 'Unitaz',
            'bidet' => 'Bide', 'hygienic_shower' => 'Gigiyenik duş', 'washer' => 'Paltaryuyan maşın',
            'dryer' => 'Qurutma maşını', 'other' => 'Digər',
        ]),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_towel_warmer', 'label' => 'Dəsmal qurudan', 'type' => 'select',
        'options' => $opt(['electric' => 'Elektrik', 'water' => 'Su', 'designer' => $designer]),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_warm_wall', 'label' => 'İsti divar', 'type' => 'select',
        'options' => $opt(['yes' => 'Bəli', 'designer' => $designer]),
        'required' => false, 'delegatable' => true],
    ['key' => $p.'_comfort', 'label' => 'Əlavə rahatlıq', 'type' => 'multiselect',
        'options' => $opt([
            'built_in_shelves' => 'Quraşdırılmış rəflər', 'hanging_shelf' => 'Asma rəf',
            'steam' => 'Buxar generatoru', 'heated_bench' => 'İsidilən skamya', 'designer' => $designer,
        ]),
        'required' => false, 'delegatable' => true],
    $roomNotes($p),
]);

/** Otaq bölməsi qısa yaradıcısı. */
$roomSection = fn (string $key, string $roomType, array $name, array $questions) => [
    'key' => $key,
    'name' => $name,
    'room_type' => $roomType,
    'estimated_minutes' => 2,
    'questions' => $questions,
];

/** Yataq / qonaq otağı üçün ortaq çarpayı eni siyahısı. */
$bedWidths = [
    '900' => '900 mm', '1200' => '1200 mm', '1400' => '1400 mm',
    '1600' => '1600 mm', '1800' => '1800 mm', '2000' => '2000 mm', 'designer' => $designer,
];

return [

    // ══════════════ BÖLMƏ 1 — SİZİN HAQQINIZDA (Roomix «О вас») ══════════════
    [
        'key' => 'about_you',
        'name' => ['az' => 'Sizin haqqınızda', 'ru' => 'О вас', 'en' => 'About you'],
        'icon' => 'user',
        'estimated_minutes' => 3,
        'intro' => ['az' => 'Eviniz məhz buradan başlayır. Ulduzlu sahələr dizaynerə ən çox kömək edir; texniki detalları sonraya saxlaya bilərsiniz.'],
        'questions' => [
            // Roomix-də bu iki blok sərbəst mətn deyil, sətir-sətir doldurulan cədvəldir.
            ['key' => 'household_members', 'group' => 'Ailənin tərkibi', 'label' => 'Ailənin tərkibi', 'type' => 'repeater',
                'options' => [
                    'add_label' => 'Ailə üzvü əlavə et',
                    'fields' => [
                        ['key' => 'role', 'label' => ['az' => 'Ailə üzvü'], 'type' => 'text'],
                        ['key' => 'name', 'label' => ['az' => 'Ad'], 'type' => 'text'],
                        ['key' => 'age', 'label' => ['az' => 'Yaş'], 'type' => 'number'],
                        ['key' => 'height', 'label' => ['az' => 'Boy, mm'], 'type' => 'number'],
                        ['key' => 'handedness', 'label' => ['az' => 'Əl üstünlüyü'], 'type' => 'select', 'options' => [
                            ['value' => 'right', 'label' => ['az' => 'Sağ']],
                            ['value' => 'left', 'label' => ['az' => 'Sol']],
                        ]],
                    ],
                ],
                'required' => false, 'delegatable' => false,
                'help' => 'Boy və əl üstünlüyü erqonomikanı hər kəs üçün ayrıca hesablamağa kömək edir.'],
            ['key' => 'hobbies_profession', 'group' => 'Maraqlar / peşə', 'label' => 'Maraqlar / peşə', 'type' => 'repeater',
                'options' => [
                    'add_label' => 'Maraq əlavə et',
                    'fields' => [
                        ['key' => 'hobby', 'label' => ['az' => 'Maraq / peşə'], 'type' => 'text'],
                        ['key' => 'need', 'label' => ['az' => 'Nə nəzərə alınmalıdır'], 'type' => 'text'],
                    ],
                ],
                'required' => false, 'delegatable' => false],

            ['key' => 'frequent_guests', 'group' => 'Həyat tərzi', 'label' => 'Tez-tez qonaq qəbul edirsiniz?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'future_plans_5_10y', 'group' => 'Həyat tərzi', 'label' => 'Yaxın 5–10 ilə planlarınız', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: uşaq doğulması, uzaqdan iş, valideynlərin köçməsi.'],
            ['key' => 'has_pets', 'group' => 'Həyat tərzi', 'label' => 'Ev heyvanlarınız var?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'pet_zone_needs', 'group' => 'Həyat tərzi', 'label' => 'Heyvanlar üzrə nə nəzərə alınmalıdır?', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Yataq, caynaq itiləyən, tualet, məhdudlaşdırıcılar.',
                'skip' => ['question' => 'has_pets', 'operator' => 'equals', 'value' => '1']],
            ['key' => 'has_allergies', 'group' => 'Həyat tərzi', 'label' => 'Ailədən kiminsə allergiyası var?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'allergy_details', 'group' => 'Həyat tərzi', 'label' => 'Allergiya üzrə qeydlər', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Diaqnoz yazmaq məcburi deyil — qaçınılmalı materiallar kifayətdir.',
                'skip' => ['question' => 'has_allergies', 'operator' => 'equals', 'value' => '1']],
            ['key' => 'works_from_home', 'group' => 'Həyat tərzi', 'label' => 'Ailədən kimsə daimi evdən işləyir?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Bəli olduqda otaqlar bölməsində kabinet əlavə etməyi unutmayın.'],
            ['key' => 'smoking_indoors', 'group' => 'Həyat tərzi', 'label' => 'Evdə siqaret çəkilirmi?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false],

            ['key' => 'lead_source', 'group' => 'Bizi haradan öyrəndiniz?', 'label' => 'Bizi haradan öyrəndiniz?', 'type' => 'multiselect',
                'options' => $opt([
                    'instagram' => 'Instagram', 'vk' => 'ВКонтакте', 'facebook' => 'Facebook',
                    'ads' => 'Reklam', 'friends' => 'Tanışlardan', 'word_of_mouth' => 'Ağızdan-ağıza',
                    'other' => 'Digər',
                ]),
                'required' => false, 'delegatable' => false],
            ['key' => 'why_chose_us', 'group' => 'Bizi haradan öyrəndiniz?', 'label' => 'Niyə məhz bizi seçdiniz?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Sizin üçün nəyin vacib olduğunu bilmək bizə kömək edir.'],
        ],
    ],

    // ══════════════════ BÖLMƏ 2 — OBYEKT (Roomix «Объект») ══════════════════
    [
        'key' => 'object',
        'name' => ['az' => 'Obyekt', 'ru' => 'Объект', 'en' => 'The property'],
        'icon' => 'home',
        'estimated_minutes' => 4,
        'intro' => ['az' => 'Məkanla tanış oluruq. Ulduzlu sahələr dizaynerə ilk növbədə lazımdır, texniki xarakteristikaları sonra dəqiqləşdirmək olar.'],
        'questions' => [
            ['key' => 'object_address', 'group' => 'Obyektin xarakteristikası', 'label' => 'Obyektin ünvanı', 'type' => 'text',
                'options' => null, 'required' => true, 'delegatable' => false,
                'help' => 'Şəhər, küçə, ev.'],
            ['key' => 'object_location_note', 'group' => 'Obyektin xarakteristikası', 'label' => 'Obyektin yerləşməsi', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: rayon, yaşayış kompleksi, oriyentir.'],
            ['key' => 'object_type', 'group' => 'Obyektin xarakteristikası', 'label' => 'Obyektin tipi', 'type' => 'select',
                'options' => $opt(['apartment' => 'Mənzil', 'house' => 'Fərdi ev']),
                'required' => true, 'delegatable' => false],
            ['key' => 'object_floor', 'group' => 'Obyektin xarakteristikası', 'label' => 'Mərtəbə / mərtəbə sayı', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: 12-dən 5-ci.'],
            ['key' => 'total_area_sqm', 'group' => 'Obyektin xarakteristikası', 'label' => 'Ümumi sahə, m²', 'type' => 'number',
                'options' => null, 'required' => true, 'delegatable' => false],
            ['key' => 'design_area_sqm', 'group' => 'Obyektin xarakteristikası', 'label' => 'Dizayn-layihə üçün sahə, m²', 'type' => 'number',
                'options' => null, 'required' => false, 'delegatable' => false],

            ['key' => 'building_year', 'group' => 'Texniki parametrlər', 'label' => 'Tikinti ili', 'type' => 'number',
                'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'ceiling_height_raw_mm', 'group' => 'Texniki parametrlər', 'label' => 'Tavanın kobud hündürlüyü, mm', 'type' => 'number',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: 2700.'],
            ['key' => 'screed_thickness_mm', 'group' => 'Texniki parametrlər', 'label' => 'Stiajkanın faktiki qalınlığı, mm', 'type' => 'number',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: 80.'],
            ['key' => 'bearing_walls', 'group' => 'Texniki parametrlər', 'label' => 'Daşıyıcı divarların materialı və qalınlığı', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: monolit, 200 mm.'],
            ['key' => 'slab_system', 'group' => 'Texniki parametrlər', 'label' => 'Arakəsmə sistemi və qalınlığı', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: monolit, 200 mm.'],
            ['key' => 'object_utilities', 'group' => 'Texniki parametrlər', 'label' => 'Obyektin mühəndis şəbəkələri', 'type' => 'multiselect',
                'options' => $opt([
                    'gas' => 'Qaz təchizatı', 'city_sewer' => 'Şəhər kanalizasiyası',
                    'private_sewer' => 'Fərdi kanalizasiya', 'water' => 'Su təchizatı',
                ]),
                'required' => false, 'delegatable' => false],
            ['key' => 'premises_purpose', 'group' => 'Texniki parametrlər', 'label' => 'Binanın təyinatı', 'type' => 'select',
                'options' => $opt([
                    'residential' => 'Yaşayış', 'apartments' => 'Apartament',
                    'commercial' => 'Kommersiya', 'mixed' => 'Qarışıq', 'unknown' => 'Bilmirəm',
                ]),
                'required' => false, 'delegatable' => false],

            ['key' => 'replace_windows', 'group' => 'Pəncərələr və pəncərəaltılar', 'label' => 'Pəncərələr dəyişdirilir?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Pəncərələrin dəyişdirilməsi planlaşdırılır.'],
            ['key' => 'replace_sills', 'group' => 'Pəncərələr və pəncərəaltılar', 'label' => 'Pəncərəaltılar dəyişdirilir?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Pəncərəaltıların dəyişdirilməsi planlaşdırılır.'],

            ['key' => 'freight_lift', 'group' => 'Logistika və materialın qaldırılması', 'label' => 'Yük lifti var?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false],
            ['key' => 'landing_width', 'group' => 'Logistika və materialın qaldırılması', 'label' => 'Pilləkən meydançasının eni', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: 1,2 m.'],
            ['key' => 'oversize_turn', 'group' => 'Logistika və materialın qaldırılması', 'label' => 'İrigabarit materialı döndərmək mümkündür?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false],
            ['key' => 'lifting_note', 'group' => 'Logistika və materialın qaldırılması', 'label' => 'Materialın qaldırılması barədə qeyd', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Mərtəbə, lift, dar keçidlər, məhdudiyyətlər.'],

            // ── Archi-yə məxsus blok (Roomix-də yoxdur, bax: fayl başlığı) ──
            // Obmer planı fayl əlavəsini və risk xəbərdarlığını qidalandırır
            // (BriefService::attachments, BriefRiskDetector).
            ['key' => 'demolition_needed', 'group' => 'Hazırlıq və ölçmə', 'label' => 'Demontaj işləri lazımdır?', 'type' => 'select',
                'options' => $opt(['yes' => 'Bəli', 'no' => 'Xeyr', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'property_readiness', 'operator' => 'not_equals', 'value' => 'new_shell']],
            ['key' => 'has_measurement_plan', 'group' => 'Hazırlıq və ölçmə', 'label' => 'Obmer planı / BTİ planı var?', 'type' => 'select',
                'options' => $opt(['yes' => 'Var, əlavə edəcəm', 'no' => 'Yox, obmer lazımdır', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => false,
                'help' => 'Obmer planı olmadıqda layihələndirmənin startından əvvəl obmer sifariş etməyi tövsiyə edirik.'],
            ['key' => 'measurement_plan_file', 'group' => 'Hazırlıq və ölçmə', 'label' => 'Obmer planı faylı', 'type' => 'file',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'PDF, JPG və ya PNG.',
                'skip' => ['question' => 'has_measurement_plan', 'operator' => 'equals', 'value' => 'yes']],

            // Roomix «Мебельные высоты» — hər sətir standart ölçü ilə öz ölçü
            // arasında seçimdir, solunda isə nümunə şəkli açılır.
            ['key' => 'furniture_heights', 'group' => 'Mebel hündürlükləri', 'label' => 'Mebel hündürlükləri', 'type' => 'std_or_custom',
                'options' => [
                    'items' => [
                        ['value' => 'kitchen_worktop', 'label' => ['az' => 'Mətbəx iş səthi'], 'standard' => 900, 'unit' => 'mm', 'images' => []],
                        ['value' => 'bath_sink', 'label' => ['az' => 'Sanuzeldə raqovina'], 'standard' => 850, 'unit' => 'mm', 'images' => []],
                        ['value' => 'rain_shower', 'label' => ['az' => 'Tropik duş'], 'standard' => 2100, 'unit' => 'mm', 'images' => []],
                        ['value' => 'wc_from_floor', 'label' => ['az' => 'Unitaz — döşəmədən'], 'standard' => 400, 'unit' => 'mm', 'images' => []],
                        ['value' => 'desk', 'label' => ['az' => 'İş masası'], 'standard' => 750, 'unit' => 'mm', 'images' => []],
                    ],
                ],
                'required' => false, 'delegatable' => true,
                'help' => 'Erqonomikanın standartı var, amma rahatlıq hər kəsdə fərqlidir. Hündürlük prinsipial deyilsə, standart variantı saxlayın.'],
        ],
    ],

    // ═════════════ BÖLMƏ 3 — KOMPLEKTASİYA (Roomix «Комплектация») ═════════════
    [
        'key' => 'procurement',
        'name' => ['az' => 'Komplektasiya', 'ru' => 'Комплектация', 'en' => 'Specification'],
        'icon' => 'shopping-bag',
        'estimated_minutes' => 3,
        'intro' => ['az' => 'Səviyyə və doldurma. Hansı material və texnikanın sizə daha yaxın olduğunu deyin — bu, dizaynerə həlləri zövqünüzə görə seçməyə kömək edir.'],
        'questions' => [
            ['key' => 'materials_preference', 'group' => 'Səviyyə və doldurma', 'label' => 'Hansı materialları üstün tutursunuz?', 'type' => 'multiselect',
                'options' => $opt([
                    'artificial' => 'Süni',
                    'natural' => 'Təbii (ağac, daş, metal, şüşə)',
                    'exclusive' => 'Eksklüziv, əl işi',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'material_brands', 'group' => 'Səviyyə və doldurma', 'label' => 'Material istehsalçıları üzrə prioritetlər', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Sevdiyiniz markalar varsa, yazın.'],
            ['key' => 'furniture_level', 'group' => 'Səviyyə və doldurma', 'label' => 'Mebel', 'type' => 'multiselect',
                'options' => $opt([
                    'budget' => 'Büdcə', 'local' => 'Yerli istehsalçılar',
                    'known_brands' => 'Tanınmış brend və fabriklər', 'european' => 'Avropa istehsalçıları',
                    'custom' => 'Sifarişlə eksklüziv', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'lighting_level', 'group' => 'Səviyyə və doldurma', 'label' => 'İşıqlandırma', 'type' => 'multiselect',
                'options' => $opt(['original' => 'Orijinal brendlər', 'replica' => 'Replikalar', 'designer' => $designer]),
                'required' => false, 'delegatable' => true],
            ['key' => 'plumbing_level', 'group' => 'Səviyyə və doldurma', 'label' => 'Santexnika', 'type' => 'multiselect',
                'options' => $opt([
                    'budget' => 'Büdcə', 'known_brands' => 'Tanınmış brendlər',
                    'exclusive' => 'Eksklüziv', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'plumbing_brands', 'group' => 'Səviyyə və doldurma', 'label' => 'Santexnika istehsalçısı seçimində prioritet varmı?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true],
            ['key' => 'doors_level', 'group' => 'Səviyyə və doldurma', 'label' => 'Qapılar', 'type' => 'multiselect',
                'options' => $opt([
                    'budget' => 'Büdcə', 'known_brands' => 'Tanınmış brend və fabriklər',
                    'custom' => 'Sifarişlə eksklüziv', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'doors_brands', 'group' => 'Səviyyə və doldurma', 'label' => 'Qapı istehsalçısı seçimində prioritet varmı?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true],
            ['key' => 'implementation_approach', 'group' => 'Səviyyə və doldurma', 'label' => 'Layihənin icrası', 'type' => 'select',
                'options' => $opt([
                    'now' => 'İnteryeri dərhal icra etmək',
                    'later' => 'İcranı təxirə salmaq',
                    'phased' => 'Layihəni mərhələlərlə icra etmək',
                ]),
                'required' => false, 'delegatable' => false],

            // ── Archi-yə məxsus blok (Roomix-də yoxdur, bax: fayl başlığı) ──
            ['key' => 'cooperation_scope', 'group' => 'Format və büdcə', 'label' => 'Əməkdaşlıq formatı', 'type' => 'select',
                'options' => $opt([
                    'design_only' => 'Yalnız dizayn-layihə',
                    'design_procurement' => 'Dizayn + komplektasiya',
                    'design_supervision' => 'Dizayn + müəllif nəzarəti',
                    'turnkey' => 'Açar təhvili (təmirlə birlikdə)',
                    'rooms_only' => 'Yalnız ayrı otaqlar',
                ]),
                'required' => true, 'delegatable' => false],
            ['key' => 'property_readiness', 'group' => 'Format və büdcə', 'label' => 'Obyektin hazırlığı', 'type' => 'select',
                'options' => $opt([
                    'new_shell' => 'Təmirsiz yeni tikili', 'resale_repaired' => 'Cari təmirlə ikinci əl',
                    'resale_raw' => 'Təmirsiz ikinci əl', 'other' => 'Digər',
                ]),
                'required' => false, 'delegatable' => false],
            ['key' => 'project_budget_range', 'group' => 'Format və büdcə', 'label' => 'Layihənin büdcə aralığı', 'type' => 'budget_range',
                'options' => ['currencies' => ['AZN', 'USD', 'EUR']],
                'required' => false, 'delegatable' => false,
                'help' => 'Aralıq dizaynerin həll səviyyəsini düzgün seçməsi üçündür; dəqiq rəqəm tələb olunmur.'],
            ['key' => 'desired_start_date', 'group' => 'Format və büdcə', 'label' => 'İstənilən başlama tarixi', 'type' => 'date',
                'options' => null, 'required' => false, 'delegatable' => false],
            ['key' => 'desired_completion_date', 'group' => 'Format və büdcə', 'label' => 'Arzuolunan bitmə tarixi', 'type' => 'date',
                'options' => null, 'required' => false, 'delegatable' => false],

            // Roomix «Подрядчики» — sətir × sütun cədvəli.
            ['key' => 'contractor_matrix', 'group' => 'Podratçılar', 'label' => 'Podratçılar', 'type' => 'matrix',
                'options' => [
                    'rows' => $opt([
                        'crew' => 'Təmir briqadası',
                        'appliances' => 'Texnika',
                        'plumbing' => 'Santexnika',
                        'hvac' => 'Kondisioner və ventilyasiya',
                        'furniture' => 'Mebel',
                        'doors' => 'Qapılar',
                        'windows' => 'Pəncərələr',
                        'curtains' => 'Pərdə və tekstil',
                        'lighting' => 'İşıq',
                        'decor' => 'Dekor',
                        'other' => 'Digər',
                    ]),
                    'columns' => $opt([
                        'own' => 'Öz podratçım var',
                        'none' => 'Yoxdur',
                        'need_recommendation' => 'Dizaynerin tövsiyəsi lazımdır',
                    ]),
                ],
                'required' => false, 'delegatable' => false,
                'help' => 'Hər iş növü üzrə öz podratçınızın olub-olmadığını qeyd edin.'],
            ['key' => 'contractor_other_category', 'group' => 'Podratçılar', 'label' => '«Digər» — hansı iş növü?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: landşaft və ya hovuz.',
                'skip' => ['question' => 'contractor_matrix', 'operator' => 'matrix_row_filled', 'value' => 'other']],
        ],
    ],

    // ══════════════════ BÖLMƏ 4 — ESTETİKA (Roomix «Эстетика») ══════════════════
    [
        'key' => 'aesthetics',
        'name' => ['az' => 'Estetika', 'ru' => 'Эстетика', 'en' => 'Aesthetics'],
        'icon' => 'sparkles',
        'estimated_minutes' => 6,
        'intro' => ['az' => 'Vizual diliniz. İnteryerin xarakteri buradan başlayır — sizə yaxın olanı işarələyin, dəqiqliyə görə narahat olmayın.'],
        'questions' => [
            ['key' => 'unified_style', 'group' => 'Üslub', 'label' => 'Bütün evdə vahid üslubu saxlamaq vacibdir?', 'type' => 'select',
                'options' => $opt([
                    'unified' => 'Bəli, bütün evdə vahid üslub',
                    'per_room' => 'Xeyr, hər otaq öz qamması və xarakterində',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            // A patterni — seçimin özü şəkildir. Şəkillər admin paneldən yüklənir.
            ['key' => 'style_preferences', 'group' => 'Üslub', 'label' => 'Sizə yaxın olan üslublar', 'type' => 'image_multiselect',
                'options' => $opt([
                    'neoclassic' => 'Neoklassika',
                    'art_deco' => 'Ar-deko',
                    'eclectic' => 'Eklektika',
                    'minimalism' => 'Minimalizm',
                    'eco' => 'Eko-üslub',
                    'japandi' => 'Capandi',
                    'scandi' => 'Skandinav',
                    'industrial' => 'İndustrial',
                    'ethnic' => 'Etnik',
                    'chalet' => 'Şale',
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'help' => 'Kartın üstünə basaraq seçin. Bir neçəsini seçmək olar.'],
            ['key' => 'own_style_refs', 'group' => 'Üslub', 'label' => 'Öz üslubum var', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Foto-referanslar yükləyin və ya nümunələr qovluğuna keçid buraxın.'],
            ['key' => 'own_style_link', 'group' => 'Üslub', 'label' => 'Referans qovluğuna keçid', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'skip' => ['question' => 'own_style_refs', 'operator' => 'equals', 'value' => '1']],

            ['key' => 'open_to_bold', 'group' => 'Konsepsiya və istəklər', 'label' => 'Qeyri-standart həllərə hazırsınız?', 'type' => 'select',
                'options' => $opt($yesNoDesigner), 'required' => false, 'delegatable' => true],
            ['key' => 'concept_basis', 'group' => 'Konsepsiya və istəklər', 'label' => 'Konseptual əsas', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: isti skandinav evinin atmosferi, sakitlik və təbii materiallar.'],
            ['key' => 'style_extra_notes', 'group' => 'Konsepsiya və istəklər', 'label' => 'Üslub üzrə əlavə istəklər', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Nə mütləq nəzərə alınmalı, nədən uzaq durmaq lazımdır.'],
            ['key' => 'favourite_item', 'group' => 'Konsepsiya və istəklər', 'label' => 'Yeni interyerə köçürmək istədiyiniz sevimli əşya varmı?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: rəsm, pərdə, xalça və ya başqa dəyərli əşya.'],
            ['key' => 'favourite_item_photo', 'group' => 'Konsepsiya və istəklər', 'label' => 'Sevimli əşyanın fotosu', 'type' => 'file',
                'options' => null, 'required' => false, 'delegatable' => false],

            ['key' => 'favourite_colors', 'group' => 'Rəng', 'label' => 'Sevimli rəngləriniz', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: isti bej, dərin yaşıl.'],
            ['key' => 'palette_in_words', 'group' => 'Rəng', 'label' => 'Qamma üzrə istəkləriniz öz sözlərinizlə', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Rəngdən hansı hissi gözlədiyinizi təsvir edin.'],
            ['key' => 'background_colors', 'group' => 'Rəng', 'label' => 'Üstünlük verilən fon rəngləri', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: bej, ağ, boz, isti çalarlar.'],
            ['key' => 'accent_colors', 'group' => 'Rəng', 'label' => 'Arzuolunan aksent rəngləri', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: terrakot, oxra, zümrüd.'],
            ['key' => 'forbidden_colors', 'group' => 'Rəng', 'label' => 'İstifadə edilməyəcək rənglər', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: parlaq qırmızı, turşu çalarları.'],
            ['key' => 'interior_character', 'group' => 'Rəng', 'label' => 'İnteryerin xarakteri', 'type' => 'select',
                'options' => $opt(['contrast' => 'Kontrast, parlaq aksentlər', 'soft' => 'Neytral pastel']),
                'required' => false, 'delegatable' => true],
            ['key' => 'tone_temperature', 'group' => 'Rəng', 'label' => 'Çalarların temperaturu', 'type' => 'select',
                'options' => $opt(['warm' => 'İsti', 'cool' => 'Soyuq', 'neutral' => 'Neytral']),
                'required' => false, 'delegatable' => true],

            // Roomix «Сочетания цветов» — 27 kart, hər birində bəyənmə / bəyənməmə.
            ['key' => 'color_combinations', 'group' => 'Rəng kombinasiyaları', 'label' => 'Rəng kombinasiyaları', 'type' => 'image_rating',
                'options' => $paletteOpt([
                    'b4a28c,836e5b,b09d8c,c7bdb3,cdbdad',
                    'cbc3b8,d2c4b9,c7bcaa,857865,8a7965',
                    'a2998a,aba293,857c6d,b7ab9f,928676',
                    'dad1c8,c6c5c3,b09e88,ad9b87,b7a58f',
                    '2d2c28,343331,8c7f6c,dbddda,dddddb',
                    '3f3e3a,d6cdc8,5f4c3d,7a6457,20221f',
                    '442e23,d3c5bc,c2b2a5,584537,49352a',
                    '3b3326,82786e,3f3c37,aba79e,383732',
                    'adada5,777777,c5c6ca,c6c5ca,dbdfe2',
                    'efedee,e3ded8,373739,959597,98989a',
                    'b5b9bc,1c293c,a4a2a5,ebeef3,d9dfeb',
                    'c1b7ad,d6d5d1,d5cbbf,83796f,ab9b8c',
                    '928f86,9e9753,a1bdc8,e3d9cd,f6efe9',
                    '29282d,d4d4d6,b0acab,bbb3a8,8e8d88',
                    'e1d9ce,524330,a18466,e4dad1,dac9c1',
                    '949585,cecbc2,40311a,dedcd0,525343',
                    '14140a,444537,696758,573418,af9e84',
                    '8f6f64,543614,c79f7b,c49687,c8b7a7',
                    'e3e2de,bfafa0,bfb2a1,a99886,ab9887',
                    'f7e9de,a3958a,bc9d8b,d8b492,97897c',
                    '41342c,86786d,502224,bdb5aa,d1c9c6',
                    '200e02,a1998c,a59d92,40311c,bab0a4',
                    '150e06,c6baae,0e0603,070302,0a0605',
                    'a69686,532315,e0d0c1,8c472a,b8baaf',
                    'b8b1a7,b89b71,635238,54401d,d3cec8',
                    '554b41,cab19d,c7b5a1,1e1204,4a3a2a',
                    '9a856a,8b7052,492e11,c6baa4,736656',
                ]),
                'required' => false, 'delegatable' => true,
                'help' => 'Hansı kombinasiyanın xoşunuza gəldiyini, hansının gəlmədiyini işarələyin. Bu, zövqünüzü tez anlamağa kömək edir.'],

            ['key' => 'preferred_metals', 'group' => 'Üstünlük verilən metallar', 'label' => 'Üstünlük verilən metallar', 'type' => 'multiselect',
                'options' => $swatchOpt([
                    'gold' => ['Qızıl', '#d4af37'],
                    'copper' => ['Mis', '#b87333'],
                    'silver' => ['Gümüş', '#c0c0c0'],
                    'iron' => ['Dəmir', '#4b4e53'],
                    'brass' => ['Latun', '#b5a642'],
                    'chrome' => ['Xrom', '#dfe2e6'],
                    'stainless' => ['Paslanmayan polad', '#8a9098'],
                    'aluminium' => ['Alüminium', '#a9adb3'],
                    'bronze' => ['Bronz', '#8c6239'],
                    'matte_gold' => ['Mat qızıl', '#c9a86a'],
                    'aged' => ['Köhnəldilmiş metallar', '#6b5d4f'],
                    'designer' => [$designer],
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'metals_note', 'group' => 'Üstünlük verilən metallar', 'label' => 'Metallar üzrə əlavə', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Harada və hansı metalı görmək istərdiniz.'],

            ['key' => 'wall_tone', 'group' => 'Səthlərin rəngi', 'label' => 'Divarların rəngi', 'type' => 'select',
                'options' => $opt(['light' => 'Açıq', 'dark' => 'Tünd', 'designer' => $designer]),
                'required' => false, 'delegatable' => true],
            ['key' => 'floor_tone', 'group' => 'Səthlərin rəngi', 'label' => 'Döşəmənin rəngi', 'type' => 'select',
                'options' => $opt(['light' => 'Açıq', 'dark' => 'Tünd', 'designer' => $designer]),
                'required' => false, 'delegatable' => true],
            ['key' => 'ceiling_tone', 'group' => 'Səthlərin rəngi', 'label' => 'Tavanın rəngi', 'type' => 'select',
                'options' => $opt(['light' => 'Açıq', 'dark' => 'Tünd', 'designer' => $designer]),
                'required' => false, 'delegatable' => true],
        ],
    ],

    // ═════════ BÖLMƏ 5 — ÖRTÜK MATERİALLARI (Roomix «Отделочные материалы») ═════════
    [
        'key' => 'finish_materials',
        'name' => ['az' => 'Örtük materialları', 'ru' => 'Отделочные материалы', 'en' => 'Finish materials'],
        'icon' => 'swatch',
        'estimated_minutes' => 4,
        'intro' => ['az' => 'Xoşunuza gələn materialları işarələyin və hansı otaqda görmək istədiyinizi yazın.'],
        'questions' => [
            ['key' => 'material_priorities', 'group' => 'Materialda nə vacibdir', 'label' => 'Materialda sizin üçün nə daha vacibdir?', 'type' => 'multiselect',
                'options' => $opt([
                    'price' => 'Qiymət', 'quality' => 'Keyfiyyət', 'eco' => 'Ekoloji təmizlik',
                    'durability' => 'Uzunömürlülük', 'practicality' => 'Praktiklik', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'finishes_fully_designer', 'group' => 'Materialda nə vacibdir', 'label' => 'Tamamilə dizaynerin ixtiyarına', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false],

            ['key' => 'wall_materials', 'group' => 'Divarlar', 'label' => 'Divar materiallarını seçin', 'type' => 'multiselect',
                'options' => $opt([
                    'wallpaper' => 'Hazır divar kağızı (fon, ornament, freska, genişformatlı)',
                    'paint' => 'Divarın boyanması',
                    'paint_molding' => 'Moldinqlə boyama',
                    'decorative_plaster' => 'Dekorativ suvaq (fakturalı, hamar, yuyulan)',
                    'concrete' => 'Təbii beton',
                    'natural_stone' => 'Təbii daş',
                    'decorative_brick' => 'Dekorativ kərpic',
                    'porcelain_tile' => 'Keramoqranit plitə (parlaq, mat, iri format)',
                    'panels_3d' => '3D panellər',
                    'wall_panels' => 'Divar panelləri (ağac, dəri, parça, gips)',
                    'bamboo' => 'Bambuk panelləri',
                    'metal' => 'Metal',
                    'moldings' => 'Moldinqlər (sadə kəsik, dekorativ suvaq naxışı)',
                    'bas_relief' => 'Barelyef (həcmli relyef)',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'finishes_fully_designer', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'skirting', 'group' => 'Divarlar', 'label' => 'Plintus', 'type' => 'multiselect',
                'options' => $opt([
                    'surface' => 'Üstdən qoyulan', 'hidden' => 'Gizli',
                    'shadow' => 'Kölgə profili', 'lit' => 'İşıqlandırmalı', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'finishes_fully_designer', 'operator' => 'not_equals', 'value' => '1']],

            ['key' => 'floor_materials', 'group' => 'Döşəmə', 'label' => 'Döşəmə materiallarını seçin', 'type' => 'multiselect',
                'options' => $opt([
                    'parquet' => 'Parket və ya mühəndis lövhəsi',
                    'laminate' => 'Laminat',
                    'quartz_vinyl' => 'Kvarsvinil',
                    'porcelain_tile' => 'Keramoqranit',
                    'natural_stone' => 'Təbii daş',
                    'microcement' => 'Mikrosement',
                    'carpet' => 'Kovrolin',
                    'poured' => 'Tökmə döşəmə',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'finishes_fully_designer', 'operator' => 'not_equals', 'value' => '1']],

            ['key' => 'ceiling_materials', 'group' => 'Tavan', 'label' => 'Tavan materiallarını seçin', 'type' => 'multiselect',
                'options' => $opt([
                    'drywall' => 'Gips-karton (GKL)',
                    'stretch_pvc' => 'Gərilmə PVC',
                    'stretch_fabric' => 'Gərilmə parça',
                    'combined' => 'Kombinə (GKL və gərilmə)',
                    'coffered' => 'Kesson (GKL və ya ağac)',
                    'beams' => 'Tirlər',
                    'ceiling_molding' => 'Tavan suvaq naxışı',
                    'keep_existing' => 'Mövcud səthləri saxlamaq',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'finishes_fully_designer', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'multilevel_ceilings', 'group' => 'Tavan', 'label' => 'Çoxsəviyyəli tavanları qəbul edirsiniz?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => true],

            ['key' => 'door_height', 'group' => 'Qapılar', 'label' => 'Qapıların hündürlüyü', 'type' => 'select',
                'options' => $opt(['std' => 'Standart', 'ceiling' => 'Tavana qədər', 'custom' => 'Qeyri-standart']),
                'required' => false, 'delegatable' => true],
            ['key' => 'door_mounting', 'group' => 'Qapılar', 'label' => 'Qapıların quraşdırılması', 'type' => 'select',
                'options' => $opt(['frame' => 'Qutu və nalişnik ilə', 'hidden' => 'Gizli montaj']),
                'required' => false, 'delegatable' => true],
            ['key' => 'door_materials', 'group' => 'Qapılar', 'label' => 'Qapı materialları', 'type' => 'multiselect',
                'options' => $opt([
                    'enamel' => 'Emal', 'film' => 'Plyonka', 'veneer' => 'Şpon', 'solid' => 'Massiv',
                    'glass' => 'Şüşə', 'mirror' => 'Güzgü', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'door_finish', 'group' => 'Qapılar', 'label' => 'Qapıların bəzəyi', 'type' => 'multiselect',
                'options' => $opt([
                    'dark' => 'Tünd', 'light' => 'Açıq', 'gloss' => 'Parlaq', 'matte' => 'Mat',
                    'textured' => 'Fakturalı', 'panelled' => 'Filyonqlu', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'door_locks', 'group' => 'Qapılar', 'label' => 'Harada kilid lazımdır?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: yataq otağı, kabinet.'],

            ['key' => 'forbidden_materials', 'group' => 'Nə olmamalıdır', 'label' => 'Hansı materiallar olmamalıdır?', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: parlaq səthlər istəmirəm.'],
        ],
    ],

    // ═════════════ BÖLMƏ 6 — İŞIQLANDIRMA (Roomix «Освещение») ═════════════
    [
        'key' => 'lighting',
        'name' => ['az' => 'İşıqlandırma', 'ru' => 'Освещение', 'en' => 'Lighting'],
        'icon' => 'light-bulb',
        'estimated_minutes' => 4,
        'intro' => ['az' => 'İşıq otağın əhvalını yaradır. Sizə yaxın işıq ssenarilərini işarələyin və pərdələr barədə danışın.'],
        'questions' => [
            ['key' => 'lighting_fully_designer', 'group' => 'İşıqlandırma', 'label' => 'Tamamilə dizaynerin ixtiyarına', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'İşıq ssenarisini dizaynerə etibar etmək.'],
            ['key' => 'light_temperature', 'group' => 'İşıqlandırma', 'label' => 'İşığın temperaturu', 'type' => 'multiselect',
                'options' => $opt([
                    '2700' => '2700K', '3000' => '3000K', '4000' => '4000K', '5000' => '5000K',
                    'tunable' => 'Dəyişən temperatur', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'lighting_fully_designer', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'ceiling_lighting', 'group' => 'İşıqlandırma', 'label' => 'Tavanda işıqlandırma', 'type' => 'multiselect',
                'options' => $opt([
                    'chandelier' => 'Çılçıraq',
                    'pendant' => 'Asma işıqlandırıcılar',
                    'recessed_spots' => 'Quraşdırılmış nöqtəvi',
                    'surface' => 'Üstdən qoyulan işıqlandırıcılar',
                    'track' => 'Trek işıqlandırıcıları',
                    'plaster' => 'Gips işıqlandırıcılar',
                    'magnetic_track' => 'Maqnit şinoprovodlar',
                    'light_profiles' => 'İşıq profilləri',
                    'led_strip' => 'LED lent (podsvetka)',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'lighting_fully_designer', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'wall_lighting', 'group' => 'İşıqlandırma', 'label' => 'Divarda işıqlandırma', 'type' => 'multiselect',
                'options' => $opt([
                    'sconce' => 'Bra',
                    'bedside' => 'Çarpayıyanı gecə lampası',
                    'recessed_wall' => 'Divara quraşdırılmış işıqlandırıcılar',
                    'light_profiles' => 'İşıq profilləri',
                    'art_lighting' => 'Rəsmlərin işıqlandırılması',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'lighting_fully_designer', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'floor_lighting', 'group' => 'İşıqlandırma', 'label' => 'Döşəmədə işıqlandırma', 'type' => 'multiselect',
                'options' => $opt([
                    'recessed_floor' => 'Döşəməyə quraşdırılmış işıqlandırıcılar',
                    'led_profile' => 'Profildə LED lent',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'lighting_fully_designer', 'operator' => 'not_equals', 'value' => '1']],
            ['key' => 'freestanding_lighting', 'group' => 'İşıqlandırma', 'label' => 'Ayrıca duran işıqlandırıcılar və podsvetka', 'type' => 'multiselect',
                'options' => $opt([
                    'floor_lamp' => 'Torşer',
                    'table_lamp' => 'Stolüstü lampa',
                    'furniture_light' => 'Mebel podsvetkası',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true,
                'skip' => ['question' => 'lighting_fully_designer', 'operator' => 'not_equals', 'value' => '1']],

            ['key' => 'dimmers', 'group' => 'Dimmerlər', 'label' => 'Dimmerlər lazımdır?', 'type' => 'select',
                'options' => $opt($yesNoDesigner), 'required' => false, 'delegatable' => true],

            ['key' => 'night_sensors', 'group' => 'Gecə işıqlandırması', 'label' => 'Qoşulma sensorları', 'type' => 'multiselect',
                'options' => $opt(['motion' => 'Hərəkət sensorları', 'presence' => 'İştirak sensorları']),
                'required' => false, 'delegatable' => true],
            ['key' => 'night_light_zones', 'group' => 'Gecə işıqlandırması', 'label' => 'Harada gecə işığı və ya podsvetka lazımdır?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: dəhliz, uşaq otağı.'],

            ['key' => 'curtains', 'group' => 'Pərdələr', 'label' => 'Pərdələr', 'type' => 'multiselect',
                'options' => $opt([
                    'long_portiere' => 'Klassik uzun portyerlər',
                    'lambrequin' => 'Lambrekin',
                    'tulle' => 'Yüngül tül',
                    'roman' => 'Roma pərdələri',
                    'roller' => 'Rulon pərdələr',
                    'blinds' => 'Jalüz',
                    'none' => 'Pərdəsiz',
                ]),
                'required' => false, 'delegatable' => true, 'inspire' => true],
            ['key' => 'blackout_zones', 'group' => 'Pərdələr', 'label' => 'Harada blackout lazımdır?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: yataq otağı, uşaq otağı.'],
        ],
    ],

    // ══════════════ BÖLMƏ 7 — OTAQLAR (Roomix «Помещения») ══════════════
    [
        'key' => 'rooms_hub',
        'name' => ['az' => 'Otaqların tərkibi', 'ru' => 'Состав помещений', 'en' => 'Rooms'],
        'icon' => 'squares-2x2',
        'estimated_minutes' => 3,
        'intro' => ['az' => 'Hər otaq haqqında öz sözlərinizlə danışın. Hansısa otaq yoxdursa, sayını 0 saxlayın — o otağın blokları açılmayacaq.'],
        'questions' => [
            ['key' => 'room_inventory', 'label' => 'Obyektdə hansı otaqlar var?', 'type' => 'room_inventory',
                'options' => $opt([
                    'hallway' => 'Dəhliz / hol',
                    'kitchen_furniture' => 'Mətbəx. Mebel',
                    'kitchen_appliances' => 'Mətbəx. Texnika',
                    'tech_kitchen' => 'Texniki mətbəx',
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
                    'staircase' => 'Pilləkən',
                    'balcony' => 'Balkon / loggiya',
                    'other_room' => 'Digər otaq',
                ]),
                'required' => true, 'delegatable' => false,
                'help' => 'Yalnız işarələdiyiniz otaqlar üzrə suallar açılacaq. Eyni tipdən bir neçə otaq varsa, sayını göstərin.'],

            // Roomix-də bölmənin sonundakı iki təkrarlanan siyahı.
            ['key' => 'extra_bathrooms', 'group' => 'Əlavə sanuzellər', 'label' => 'Əlavə sanuzellər', 'type' => 'repeater',
                'options' => [
                    'add_label' => 'Sanuzel əlavə et',
                    'fields' => [
                        ['key' => 'name', 'label' => ['az' => 'Adı'], 'type' => 'text'],
                        ['key' => 'fixtures', 'label' => ['az' => 'Təchizat'], 'type' => 'text'],
                        ['key' => 'towel_warmer', 'label' => ['az' => 'Dəsmal qurudan'], 'type' => 'text'],
                        ['key' => 'warm_wall', 'label' => ['az' => 'İsti divar'], 'type' => 'text'],
                        ['key' => 'notes', 'label' => ['az' => 'Əlavə istəklər'], 'type' => 'text'],
                    ],
                ],
                'required' => false, 'delegatable' => false,
                'help' => 'Yuxarıda olmayan sanuzelləri əlavə edin — qonaq üçün, birinci mərtəbədə, kabinet yanında və s.'],
            ['key' => 'other_rooms', 'group' => 'Digər otaqlar', 'label' => 'Digər otaqlar', 'type' => 'repeater',
                'options' => [
                    'add_label' => 'Otaq əlavə et',
                    'fields' => [
                        ['key' => 'name', 'label' => ['az' => 'Adı'], 'type' => 'text'],
                        ['key' => 'description', 'label' => ['az' => 'Təsviri'], 'type' => 'text'],
                    ],
                ],
                'required' => false, 'delegatable' => false,
                'help' => 'Siyahıda olmayan otaqlar: terras, loggiya, anbar, şərab zirzəmisi və digərləri.'],

            ['key' => 'home_library', 'group' => 'Əlavə üstünlüklər', 'label' => 'Ev kitabxanası', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => true],
            ['key' => 'need_safe', 'group' => 'Əlavə üstünlüklər', 'label' => 'Seyf lazımdır?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => true],
            ['key' => 'decor_items', 'group' => 'Əlavə üstünlüklər', 'label' => 'Dekor', 'type' => 'multiselect',
                'options' => $opt([
                    'paintings' => 'Rəsmlər', 'sculptures' => 'Heykəllər', 'vases' => 'Vazalar',
                    'panno' => 'Dekorativ panno', 'mirrors' => 'Güzgülər',
                    'family_photos' => 'Ailə fotoları', 'carpets' => 'Xalçalar',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'greenery', 'group' => 'Əlavə üstünlüklər', 'label' => 'Yaşıllaşdırma', 'type' => 'multiselect',
                'options' => $opt([
                    'fitopanno' => 'Fitopanno', 'pots' => 'Kaşpoda bitkilər',
                    'ceiling' => 'Tavan yaşıllaşdırması', 'moss' => 'Mamırdan panellər',
                    'large' => 'İrigabaritli bitkilər', 'winter_garden' => 'Qış bağı',
                ]),
                'required' => false, 'delegatable' => true],
        ],
    ],

    // ── Otaq blokları (yalnız room_inventory-də seçilənlər göstərilir) ──

    $roomSection('room_hallway', 'hallway', ['az' => 'Dəhliz / hol', 'ru' => 'Прихожая / холл', 'en' => 'Hallway'], [
        ['key' => 'hall_wardrobes', 'label' => 'Şkaflar', 'type' => 'multiselect',
            'options' => $opt([
                'swing' => 'Qanadlı şkaf', 'sliding' => 'Kupe şkaf', 'hanging' => 'Asma şkaf',
                'built_in' => 'Quraşdırılmış şkaf', 'walk_in' => 'Ayrıca qarderob otağı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'hall_shoes', 'label' => 'Ayaqqabı', 'type' => 'multiselect',
            'options' => $opt([
                'closed' => 'Qapalı ayaqqabı saxlanması', 'semi' => 'Qismən qapalı saxlama',
                'tall' => 'Hündür ayaqqabının saxlanması', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'hall_shoes_count', 'label' => 'Təxmini ayaqqabı cütü sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'hall_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt([
                'console' => 'Konsol', 'banquette' => 'Banketka', 'sofa' => 'Divan',
                'utility_cabinet' => 'Təsərrüfat şkafı', 'laundry_zone' => 'Paltaryuma zonası',
                'tree_spot' => 'Yeni il ağacı üçün yer', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'hall_mirror', 'label' => 'Güzgü', 'type' => 'multiselect',
            'options' => $opt([
                'full_height' => 'Tam boy', 'over_console' => 'Konsolun üstündə',
                'on_doors' => 'Qapıların üstündə', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'hall_tech', 'label' => 'Texnika', 'type' => 'multiselect',
            'options' => $opt([
                'video_intercom' => 'Videodomofon', 'cameras' => 'Videokameralar', 'router' => 'Router',
                'panel' => 'Elektrik şitoku', 'gadget_charge' => 'Qadcetlər üçün şarj',
                'robot_vacuum' => 'Robot-tozsoran', 'manifold' => 'Kollektor qovşağı',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'hall_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false,
            'help' => 'Məsələn: robot-tozsoran, proyektor, qəhvə stansiyası.'],
        $roomNotes('hall'),
    ]),

    $roomSection('room_kitchen_furniture', 'kitchen_furniture', ['az' => 'Mətbəx. Mebel', 'ru' => 'Кухня. Мебель', 'en' => 'Kitchen. Furniture'], [
        ['key' => 'kitchen_set', 'label' => 'Mətbəx dəsti', 'type' => 'multiselect',
            'options' => $opt([
                'to_ceiling' => 'Antresol (mətbəx tavana qədər)', 'no_uppers' => 'Yuxarı şkafsız',
                'hidden' => 'Gizli mətbəx', 'island' => 'Ada', 'bar' => 'Bar piştaxtası',
                'display' => 'Qab-qacaq üçün vitrin', 'wine' => 'Bar / Şərab şkafı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_worktop_material', 'label' => 'Mətbəx iş səthi', 'type' => 'multiselect',
            'options' => $opt([
                'natural_stone' => 'Təbii daş', 'quartz' => 'Kvars aqlomerat',
                'acrylic' => 'Akril daş', 'porcelain' => 'Keramoqranit',
                'unified' => 'İş səthi və fartuk vahid üslubda', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_worktop_other', 'label' => 'İş səthinin digər materialı', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'kitchen_backsplash_match', 'label' => 'Fartuk və iş səthi vahid qammada olsun?', 'type' => 'boolean',
            'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_sink', 'label' => 'Qabyuyan', 'type' => 'multiselect',
            'options' => $opt([
                'single' => 'Tək', 'double' => 'İkiqat', 'with_wing' => 'Qanadlı',
                'drinking_filter' => 'İçməli su filtri', 'disposer' => 'Qida tullantısı doğrayıcısı',
                'soap_dispenser' => 'Yuyucu vasitə dozatoru', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_sink_size', 'label' => 'Qabyuyanın ölçüsü', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => true],
        $roomNotes('kitchen_furniture'),
    ]),

    $roomSection('room_kitchen_appliances', 'kitchen_appliances', ['az' => 'Mətbəx. Texnika', 'ru' => 'Кухня. Техника', 'en' => 'Kitchen. Appliances'], [
        ['key' => 'kitchen_fridge', 'label' => 'Soyuducu', 'type' => 'multiselect',
            'options' => $opt([
                'built_in' => 'Quraşdırılan', 'freestanding' => 'Ayrıca duran',
                'side_by_side' => 'Side-by-Side / çoxqapılı', 'mini' => 'Mini-soyuducu',
                'with_freezer' => 'Dondurucu kamerası ilə', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_freezer', 'label' => 'Dondurucu', 'type' => 'multiselect',
            'options' => $opt([
                'built_in' => 'Quraşdırılan', 'free_to_1000' => 'Ayrıca duran, 1000 mm-ə qədər',
                'free_over_1000' => 'Ayrıca duran, 1000 mm-dən hündür', 'chest' => 'Dondurucu sandıq',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_hob', 'label' => 'Bişirmə səthi', 'type' => 'multiselect',
            'options' => $opt([
                'gas' => 'Qaz', 'electric' => 'Elektrik', 'induction' => 'İnduksiya',
                'with_oven' => 'Sobalı ayrıca duran', 'integrated_hood' => 'İnteqrasiya olunmuş hava çəkəni ilə',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_burners', 'label' => 'Konfor sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_oven', 'label' => 'Soba', 'type' => 'multiselect',
            'options' => $opt([
                'gas' => 'Qaz', 'electric' => 'Elektrik', 'under_hob' => 'Bişirmə səthinin altında',
                'in_column' => 'Penala quraşdırılan',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_hood', 'label' => 'Hava çəkən', 'type' => 'multiselect',
            'options' => $opt([
                'wall' => 'Divar', 'corner' => 'Künc', 'island' => 'Ada',
                'in_upper' => 'Yuxarı fasada quraşdırılan', 'in_worktop' => 'İş səthinə quraşdırılan',
                'in_hob' => 'Bişirmə səthinə inteqrasiya olunmuş', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_dishwasher', 'label' => 'Qabyuyan maşın', 'type' => 'multiselect',
            'options' => $opt(['450' => '450 mm', '600' => '600 mm']),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_washer', 'label' => 'Paltaryuyan maşın', 'type' => 'multiselect',
            'options' => $opt([
                'freestanding' => 'Ayrıca duran', 'built_in' => 'Quraşdırılan',
                'with_dryer' => 'Qurutma ilə', 'not_needed' => 'Mətbəxdə lazım deyil',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_coffee', 'label' => 'Qəhvə maşını', 'type' => 'multiselect',
            'options' => $opt(['freestanding' => 'Ayrıca duran', 'built_in' => 'Quraşdırılan']),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_microwave', 'label' => 'Mikrodalğalı soba', 'type' => 'multiselect',
            'options' => $opt(['freestanding' => 'Ayrıca duran', 'built_in' => 'Quraşdırılan']),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_wine_cabinet', 'label' => 'Şərab şkafı', 'type' => 'multiselect',
            'options' => $opt(['to_1000' => '1000 mm-ə qədər', 'over_1000' => '1000 mm-dən çox']),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt([
                'disposer' => 'Qida tullantısı doğrayıcısı', 'water_cooler' => 'Su kuleri',
                'drinking_filtration' => 'Suyun içməli səviyyəyə qədər filtrasiyası',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'kitchen_tech_hidden', 'label' => 'Məişət texnikası şkaflarda saxlanılsın?', 'type' => 'boolean',
            'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'kitchen_extra_worktop', 'label' => 'Texnika üçün əlavə iş səthi lazımdır?', 'type' => 'boolean',
            'options' => null, 'required' => false, 'delegatable' => true],
    ]),

    $roomSection('room_tech_kitchen', 'tech_kitchen', ['az' => 'Texniki mətbəx', 'ru' => 'Техническая кухня', 'en' => 'Utility kitchen'], [
        ['key' => 'tech_kitchen_purpose', 'label' => 'Təyinatı', 'type' => 'select',
            'options' => $opt(['daily' => 'Gündəlik istifadə', 'events' => 'Müvəqqəti istifadə (tədbirlər)']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_staff', 'label' => 'Mətbəxdə işçi sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'tech_kitchen_equipment', 'label' => 'Avadanlığın tipi', 'type' => 'select',
            'options' => $opt(['professional' => 'Peşəkar', 'household' => 'Məişət', 'other' => 'Digər']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_set', 'label' => 'Mətbəx dəsti', 'type' => 'multiselect',
            'options' => $opt([
                'to_ceiling' => 'Antresol (mətbəx tavana qədər)', 'no_uppers' => 'Yuxarı şkafsız',
                'hidden' => 'Gizli mətbəx', 'island' => 'Ada', 'bar' => 'Bar piştaxtası',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_work_zone', 'label' => 'İş zonası', 'type' => 'multiselect',
            'options' => $opt(['island' => 'Mətbəx adası', 'mobile_island' => 'Mobil mətbəx adası', 'other' => 'Digər']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_storage', 'label' => 'Saxlama', 'type' => 'multiselect',
            'options' => $opt(['dishes' => 'Qab-qacaq / mətbəx ləvazimatı', 'food' => 'Təzə / dondurulmuş ərzaq']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_fridge', 'label' => 'Soyuducu', 'type' => 'multiselect',
            'options' => $opt([
                'built_in' => 'Quraşdırılan', 'freestanding' => 'Ayrıca duran',
                'side_by_side' => 'Side-by-Side', 'mini' => 'Mini-soyuducu',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_hob', 'label' => 'Bişirmə səthi', 'type' => 'multiselect',
            'options' => $opt([
                'gas' => 'Qaz', 'electric' => 'Elektrik', 'induction' => 'İnduksiya',
                'with_oven' => 'Sobalı ayrıca duran',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_hood', 'label' => 'Hava çəkən', 'type' => 'multiselect',
            'options' => $opt(['wall' => 'Divar', 'corner' => 'Künc', 'island' => 'Ada', 'built_in' => 'Quraşdırılan']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_dishwasher', 'label' => 'Qabyuyan maşın', 'type' => 'multiselect',
            'options' => $opt(['450' => '450 mm', '600' => '600 mm']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_freezer', 'label' => 'Dondurucu', 'type' => 'multiselect',
            'options' => $opt(['built_in' => 'Quraşdırılan', 'freestanding' => 'Ayrıca duran', 'chest' => 'Dondurucu sandıq']),
            'required' => false, 'delegatable' => true],
        ['key' => 'tech_kitchen_oven', 'label' => 'Soba', 'type' => 'multiselect',
            'options' => $opt(['gas' => 'Qaz', 'electric' => 'Elektrik', 'in_column' => 'Penala quraşdırılan']),
            'required' => false, 'delegatable' => true],
        $roomNotes('tech_kitchen'),
    ]),

    $roomSection('room_dining', 'dining', ['az' => 'Yeməkxana', 'ru' => 'Столовая', 'en' => 'Dining room'], [
        ['key' => 'dining_persons', 'label' => 'Nəfər sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'dining_table', 'label' => 'Yemək masası', 'type' => 'multiselect',
            'options' => $opt([
                'round' => 'Dairəvi', 'oval' => 'Oval', 'square' => 'Kvadrat',
                'rect' => 'Düzbucaqlı', 'extendable' => 'Açılan', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'dining_table_top', 'label' => 'Masanın səthi', 'type' => 'multiselect',
            'options' => $opt([
                'wood' => 'Ağac', 'metal' => 'Metal', 'glass' => 'Şüşə',
                'stone' => 'Daş / keramika', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'dining_chairs', 'label' => 'Stullar', 'type' => 'multiselect',
            'options' => $opt([
                'wooden' => 'Taxta', 'plastic' => 'Plastik', 'metal_frame' => 'Metal karkas',
                'upholstered' => 'Yumşaq üzlüklü', 'with_arms' => 'Qolçaqlı',
                'folding' => 'Qatlanan', 'stools' => 'Taburetlər', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'dining_chairs_count', 'label' => 'Stulların sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => false],
        $roomNotes('dining'),
    ]),

    $roomSection('room_living', 'living', ['az' => 'Qonaq otağı', 'ru' => 'Гостиная', 'en' => 'Living room'], [
        ['key' => 'living_scenarios', 'label' => 'Qonaq otağında həyat ssenarisi', 'type' => 'multiselect',
            'options' => $opt([
                'movies' => 'Film və seriallara baxmaq (böyük ekran, akustika, qaranlıqlaşdırma vacibdir)',
                'guests' => 'Qonaq qəbulu (bir neçə nəfər üçün rahat oturacaq, ünsiyyət zonası)',
                'family' => 'Ailəvi istirahət (yumşaq zona, uşaqlar üçün təhlükəsizlik, rahatlıq)',
                'work' => 'İş və ya təhsil (masa, yaxşı işıq, sakitlik lazımdır)',
                'reading' => 'Mütaliə (rahat kreslo, lokal işıq, sakitlik)',
                'games' => 'Oyunlar (stolüstü və ya videooyunlar, saxlama yeri)',
                'parties' => 'Bayram və şənliklərin keçirilməsi (transformasiya olunan mebel, yer)',
                'background' => 'Fon vaxt keçirmə (TV «fonda», musiqi, rahatlama)',
                'sport' => 'İdman / yoqa (sərbəst zona, xalça, transformasiya imkanı)',
                'sleep' => 'Yuxu / qonaqların gecələməsi (açılan divan)',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_sofa', 'label' => 'Divan', 'type' => 'multiselect',
            'options' => $opt([
                'straight' => 'Düz', 'corner' => 'Künc', 'ottoman' => 'Ottomanlı',
                'modular' => 'Modul', 'folding' => 'Açılan', 'leather' => 'Dəri',
                'fabric' => 'Parça', 'second' => 'İkinci divan', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_sofa_note', 'label' => 'Əlavə (marka, ölçülər)', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'living_armchairs', 'label' => 'Kreslolar', 'type' => 'multiselect',
            'options' => $opt([
                'standard' => 'Standart', 'with_banquette' => 'Banketka ilə',
                'rocking' => 'Yellənən kreslo', 'massage' => 'Masaj funksiyalı',
                'hanging' => 'Asma', 'pouf' => 'Puf', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_tables', 'label' => 'Stolcuq', 'type' => 'multiselect',
            'options' => $opt(['journal' => 'Jurnal', 'coffee' => 'Qəhvə', 'side' => 'Yanaşma', 'designer' => $designer]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_tv_zone', 'label' => 'TV zonası', 'type' => 'multiselect',
            'options' => $opt([
                'closed_wall' => 'Qapalı divar mebeli', 'open_shelves' => 'Açıq rəflər',
                'tv_stand' => 'TV altı tumba', 'niche' => 'TV üçün niş', 'display' => 'Vitrin',
                'hanging' => 'Asma mebel', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_fireplace', 'label' => 'Kamin', 'type' => 'multiselect',
            'options' => $opt([
                'electric' => 'Elektrik', 'bio' => 'Bio-kamin', 'steam' => 'Buxar',
                'wood' => 'Odun', 'island' => 'Ada-kamin', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt([
                'desk' => 'Yazı masası', 'desk_chair' => 'Masa üçün kreslo',
                'bookcase' => 'Kitab şkafı', 'instruments' => 'Musiqi alətləri',
                'tree_spot' => 'Yeni il ağacı üçün yer',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_tech', 'label' => 'Texnika', 'type' => 'multiselect',
            'options' => $opt([
                'tv' => 'TV', 'projector' => 'Videoproyektor', 'home_cinema' => 'Ev kinoteatrı',
                'pro_audio' => 'Peşəkar akustika', 'home_audio' => 'Məişət akustikası',
                'soundbar' => 'Saundbar', 'music_center' => 'Musiqi mərkəzi',
                'console' => 'Oyun konsolu', 'turntable' => 'Vinil oxuducu', 'smart_speaker' => 'Ağıllı dinamik',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'living_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        $roomTvDiagonal('living'),
        $roomNotes('living'),
    ]),

    $roomSection('room_bathroom', 'bathroom', ['az' => 'Ümumi sanuzel', 'ru' => 'Общий санузел', 'en' => 'Shared bathroom'], $bathroomBlock('bath')),

    $roomSection('room_bedroom', 'bedroom', ['az' => 'Əsas yataq otağı', 'ru' => 'Главная спальня', 'en' => 'Main bedroom'], [
        ['key' => 'bedroom_bed_width', 'label' => 'Yataq yerinin eni', 'type' => 'select',
            'options' => $opt($bedWidths), 'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_headboard', 'label' => 'Başlıq və çarpayı opsiyaları', 'type' => 'multiselect',
            'options' => $opt([
                'standard' => 'Standart başlıq', 'to_ceiling' => 'Tavana qədər başlıq',
                'lift' => 'Qaldırıcı mexanizmli', 'storage' => 'Saxlama sistemi ilə',
                'massage' => 'Masaj funksiyalı', 'crib_nearby' => 'Yanında uşaq çarpayısı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_soft_furniture', 'label' => 'Yumşaq mebel', 'type' => 'multiselect',
            'options' => $opt([
                'banquette' => 'Çarpayıyanı banketka', 'armchair' => 'Kreslo',
                'pouf' => 'Puf', 'chaise' => 'Kuşetka', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt([
                'desk' => 'İş masası və stul', 'reading' => 'Mütaliə yeri',
                'crib' => 'Uşaq çarpayısı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_storage', 'label' => 'Saxlama', 'type' => 'multiselect',
            'options' => $opt([
                'walk_in' => 'Qarderob otağı', 'swing_wardrobe' => 'Qanadlı şkaf',
                'shelving' => 'Stellaj', 'chest' => 'Komod', 'bedside_table' => 'Çarpayıyanı stolcuq',
                'bedside_cabinet' => 'Çarpayıyanı tumba', 'vanity' => 'Tualet masası',
                'console' => 'Konsol', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_mirror', 'label' => 'Güzgü', 'type' => 'multiselect',
            'options' => $opt([
                'over_vanity' => 'Tualet masasının üstündə', 'freestanding' => 'Ayrıca duran',
                'full_height' => 'Tam boy', 'on_wardrobe' => 'Şkafın qapağında',
                'lit' => 'İşıqlandırmalı', 'decorative' => 'Dekorativ', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_tech', 'label' => 'Texnika', 'type' => 'multiselect',
            'options' => $opt(['tv' => 'TV', 'humidifier' => 'Hava nəmləndiricisi', 'smart_speaker' => 'Ağıllı dinamik']),
            'required' => false, 'delegatable' => true],
        ['key' => 'bedroom_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        $roomTvDiagonal('bedroom'),
        $roomNotes('bedroom'),
    ]),

    $roomSection('room_wardrobe_master', 'wardrobe_master', ['az' => 'Yataq otağı yanında qarderob', 'ru' => 'Гардеробная при спальне', 'en' => 'Master wardrobe'], [
        ['key' => 'wardrobe_master_count', 'label' => 'Qarderobların sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'wardrobe_master_type', 'label' => 'Tipi', 'type' => 'select',
            'options' => $opt(['combined' => 'Birləşmiş', 'separate' => 'Ayrı']),
            'required' => false, 'delegatable' => true],
        ['key' => 'wardrobe_master_storage_type', 'label' => 'Saxlama tipi', 'type' => 'multiselect',
            'options' => $opt(['open' => 'Açıq saxlama', 'closed' => 'Qapalı saxlama', 'semi' => 'Qismən qapalı']),
            'required' => false, 'delegatable' => true],
        ['key' => 'wardrobe_master_fill', 'label' => 'Doldurma', 'type' => 'multiselect',
            'options' => $opt([
                'rails' => 'Ştanqlar', 'long_hangers' => 'Uzun geyim üçün asacaqlar',
                'pantograph' => 'Pantoqraf', 'trouser_hangers' => 'Şalvar üçün asacaqlar',
                'drawers' => 'Çıxarılan siyirmələr', 'open_shelves' => 'Açıq rəflər',
                'baskets' => 'Səbətlər', 'shoe_modules' => 'Ayaqqabı modul sistemləri',
                'accessories' => 'Aksesuarların saxlanması', 'large_items' => 'İrigabarit əşyaların saxlanması',
                'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'wardrobe_master_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt([
                'full_mirror' => 'Tam boy güzgü', 'lit_mirror' => 'İşıqlandırmalı güzgü',
                'island_chest' => 'Komod-ada', 'pouf' => 'Puf / banketka',
                'vanity' => 'Tualet masası', 'fur_fridge' => 'Xəz üçün soyuducu',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'wardrobe_master_safe', 'label' => 'Seyf', 'type' => 'multiselect',
            'options' => $opt([
                'documents' => 'Sənədlər üçün', 'weapons' => 'Silah üçün', 'jewellery' => 'Zərgərlik üçün',
            ]),
            'required' => false, 'delegatable' => true],
        $roomNotes('wardrobe_master'),
    ]),

    $roomSection('room_bathroom_master', 'bathroom_master', ['az' => 'Yataq otağı yanında sanuzel', 'ru' => 'Санузел при спальне', 'en' => 'Master bathroom'],
        $bathroomBlock('bath_master', [
            ['key' => 'bath_master_count', 'label' => 'Sanuzellərin sayı', 'type' => 'number',
                'options' => null, 'required' => false, 'delegatable' => false],
        ])),

    $roomSection('room_kids', 'kids', ['az' => 'Uşaq yataq otağı', 'ru' => 'Детская спальня', 'en' => 'Children bedroom'], [
        ['key' => 'kids_name', 'label' => 'Uşağın / uşaqların adı (otaq ikisi üçündürsə)', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'kids_age', 'label' => 'Yaşı', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'kids_hobbies', 'label' => 'Maraqlar / sevimli personajlar', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'kids_favourite_color', 'label' => 'Sevimli rəng', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'kids_grow_with', 'label' => 'İnteryer böyüməyə uyğun olsun?', 'type' => 'boolean',
            'options' => null, 'required' => false, 'delegatable' => true],
        ['key' => 'kids_bed', 'label' => 'Çarpayı', 'type' => 'multiselect',
            'options' => $opt([
                'lift' => 'Qaldırıcı mexanizmli', 'legs' => 'Mexanizmsiz, ayaqlı',
                'bunk' => 'İkimərtəbəli', 'house' => 'Ev-çarpayı', 'sofa' => 'Açılan divan',
                'hidden' => 'Şkafa gizlənən', 'newborn' => 'Yenidoğulmuşlar üçün', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kids_soft_furniture', 'label' => 'Yumşaq mebel', 'type' => 'multiselect',
            'options' => $opt([
                'banquette' => 'Çarpayıyanı banketka', 'armchair' => 'Kreslo', 'sofa' => 'Divan',
                'hanging_chair' => 'Asma kreslo', 'soft_sill' => 'Yumşaq pəncərəaltı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kids_storage', 'label' => 'Saxlama', 'type' => 'multiselect',
            'options' => $opt([
                'walk_in' => 'Qarderob', 'sliding' => 'Kupe şkaf', 'swing' => 'Qanadlı şkaf',
                'chest' => 'Komod', 'shelving' => 'Stellaj', 'bedside' => 'Çarpayıyanı tumba',
                'vanity' => 'Tualet masası', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kids_desk', 'label' => 'Yazı masası', 'type' => 'multiselect',
            'options' => $opt([
                'standard' => 'Standart', 'height_adjustable' => 'Hündürlüyü tənzimlənən',
                'with_sockets' => 'Quraşdırılmış rozetkalarla', 'multi_person' => 'Bir neçə nəfər üçün',
                'with_cabinet' => 'Tumbalı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kids_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt([
                'climbing_wall' => 'Skalodrom', 'wall_bars' => 'İsveç divarı',
                'instruments' => 'Musiqi alətləri', 'pet_corner' => 'Zooguşə',
                'board' => 'Təbaşir / marker lövhəsi', 'kids_table' => 'Uşaq stolcuğu',
                'changing_table' => 'Bələmə stolcuğu',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kids_tech', 'label' => 'Texnika', 'type' => 'multiselect',
            'options' => $opt([
                'tv' => 'TV', 'console' => 'Oyun konsolu', 'desktop' => 'Stasionar kompüter',
                'smart_speaker' => 'Ağıllı dinamik', 'humidifier' => 'Hava nəmləndiricisi',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'kids_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        $roomTvDiagonal('kids'),
        $roomNotes('kids'),
    ]),

    $roomSection('room_wardrobe_kids', 'wardrobe_kids', ['az' => 'Uşaq otağı yanında qarderob', 'ru' => 'Гардеробная при детской', 'en' => 'Kids wardrobe'], [
        ['key' => 'wardrobe_kids_storage_type', 'label' => 'Saxlama', 'type' => 'multiselect',
            'options' => $opt(['open' => 'Açıq saxlama', 'closed' => 'Qapalı saxlama', 'semi' => 'Qismən qapalı saxlama']),
            'required' => false, 'delegatable' => true],
        ['key' => 'wardrobe_kids_fill', 'label' => 'Doldurma', 'type' => 'multiselect',
            'options' => $opt([
                'rails' => 'Ştanqlar', 'long_hangers' => 'Uzun geyim üçün asacaqlar',
                'pantograph' => 'Pantoqraf', 'trouser_rack' => 'Şalvarlıq',
                'toys' => 'Oyuncaqların saxlanması', 'large_items' => 'İrigabarit əşyaların saxlanması',
                'open_shelves' => 'Açıq rəflər',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'wardrobe_kids_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt(['full_mirror' => 'Tam boy güzgü', 'island_chest' => 'Komod-ada', 'pouf' => 'Puf / banketka']),
            'required' => false, 'delegatable' => true],
        $roomNotes('wardrobe_kids'),
    ]),

    $roomSection('room_bathroom_kids', 'bathroom_kids', ['az' => 'Uşaq otağı yanında sanuzel', 'ru' => 'Санузел при детской', 'en' => 'Kids bathroom'],
        $bathroomBlock('bath_kids', [
            ['key' => 'bath_kids_style', 'label' => 'Tərtibat üslubu', 'type' => 'select',
                'options' => $opt(['playful' => 'Parlaq uşaq dizaynı', 'adult' => 'Böyüklər üçün olduğu kimi']),
                'required' => false, 'delegatable' => true],
        ])),

    $roomSection('room_study', 'study', ['az' => 'Kabinet', 'ru' => 'Кабинет', 'en' => 'Study'], [
        ['key' => 'study_desk', 'label' => 'İş masası', 'type' => 'multiselect',
            'options' => $opt([
                'built_in_drawers' => 'Quraşdırılmış siyirmələrlə', 'locked_drawer' => 'Kilidli siyirmə ilə',
                'built_in_sockets' => 'Quraşdırılmış rozetkalar', 'meetings' => 'Danışıqlar üçün (bir neçə nəfər)',
                'task_light' => 'İş yerinin əlavə işıqlandırılması',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_chair', 'label' => 'Masa üçün kreslo', 'type' => 'multiselect',
            'options' => $opt(['office' => 'Ofis', 'gaming' => 'Oyun', 'chair' => 'Kreslo-stul']),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_sofa', 'label' => 'Divan', 'type' => 'multiselect',
            'options' => $opt([
                'straight' => 'Düz', 'corner' => 'Künc', 'folding' => 'Açılan',
                'second' => 'İkinci divan', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_armchair', 'label' => 'Kreslo', 'type' => 'multiselect',
            'options' => $opt([
                'with_banquette' => 'Banketka ilə', 'rocking' => 'Yellənən kreslo',
                'recliner' => 'Reklayner', 'massage' => 'Masaj funksiyalı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_storage', 'label' => 'Saxlama', 'type' => 'multiselect',
            'options' => $opt([
                'books' => 'Kitabların saxlanması', 'decorative_open' => 'Dekorativ açıq zona',
                'closed' => 'Qapalı saxlama', 'open' => 'Açıq saxlama', 'combined' => 'Kombinə saxlama',
                'large_items' => 'İrigabarit əşyalar üçün şkaf', 'clothes' => 'Geyim şkafı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_tv_zone', 'label' => 'TV zonası', 'type' => 'multiselect',
            'options' => $opt([
                'wall_unit' => 'Divar mebeli / TV tumbası', 'shelving' => 'Stellaj',
                'niche' => 'TV üçün niş', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt([
                'fireplace' => 'Kamin', 'safe' => 'Sənəd / silah üçün seyf', 'minibar' => 'Mini-bar / soyuducu',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_tech', 'label' => 'Texnika', 'type' => 'multiselect',
            'options' => $opt([
                'tv' => 'TV / monitor', 'projector' => 'Proyektor + ekran', 'desktop' => 'Stasionar kompüter',
                'aio' => 'Monoblok', 'laptop' => 'Noutbuk', 'printer' => 'MFU / printer',
                'console' => 'Oyun konsolu', 'smart_speaker' => 'Ağıllı dinamik', 'gaming_desk' => 'Oyun masası',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'study_monitors', 'label' => 'Monitorların sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'study_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        $roomNotes('study'),
    ]),

    $roomSection('room_guest_bedroom', 'guest_bedroom', ['az' => 'Qonaq yataq otağı', 'ru' => 'Гостевая спальня', 'en' => 'Guest bedroom'], [
        ['key' => 'guest_bed_width', 'label' => 'Çarpayının eni', 'type' => 'select',
            'options' => $opt($bedWidths), 'required' => false, 'delegatable' => true],
        ['key' => 'guest_headboard', 'label' => 'Başlıq və mexanizm', 'type' => 'multiselect',
            'options' => $opt([
                'standard' => 'Başlıq (standart, tavana qədər)',
                'lift_storage' => 'Qaldırıcı mexanizm və saxlama sistemi ilə',
                'massage' => 'Masaj funksiyalı', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'guest_soft_furniture', 'label' => 'Yumşaq mebel', 'type' => 'multiselect',
            'options' => $opt([
                'banquette' => 'Çarpayıyanı banketka', 'armchair' => 'Kreslo',
                'pouf' => 'Puf', 'chaise' => 'Kuşetka', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'guest_storage', 'label' => 'Geyim və aksesuarların saxlanması', 'type' => 'multiselect',
            'options' => $opt([
                'walk_in' => 'Qarderob otağı', 'swing_wardrobe' => 'Qanadlı şkaf',
                'shelving' => 'Stellaj', 'chest' => 'Komod', 'bedside_table' => 'Çarpayıyanı stolcuq',
                'bedside_cabinet' => 'Çarpayıyanı tumba', 'vanity' => 'Tualet masası',
                'console' => 'Konsol', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'guest_mirror', 'label' => 'Güzgü', 'type' => 'multiselect',
            'options' => $opt([
                'over_vanity' => 'Tualet masasının üstündə', 'freestanding' => 'Ayrıca duran',
                'full_height' => 'Tam boy', 'on_wardrobe' => 'Şkafın qapağında',
                'lit' => 'İşıqlandırmalı', 'decorative' => 'Dekorativ element kimi', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'guest_extras', 'label' => 'Əlavə', 'type' => 'multiselect',
            'options' => $opt(['desk' => 'İş masası və stul', 'reading' => 'Mütaliə yeri', 'designer' => $designer]),
            'required' => false, 'delegatable' => true],
        ['key' => 'guest_tech', 'label' => 'Texnika', 'type' => 'multiselect',
            'options' => $opt([
                'tv' => 'TV', 'humidifier' => 'Hava nəmləndiricisi',
                'smart_speaker' => 'Ağıllı dinamik', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'guest_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        $roomTvDiagonal('guest'),
        $roomNotes('guest'),
    ]),

    $roomSection('room_laundry', 'laundry', ['az' => 'Paltaryuyan otaq', 'ru' => 'Постирочная', 'en' => 'Laundry room'], [
        ['key' => 'laundry_tech', 'label' => 'Texnika', 'type' => 'multiselect',
            'options' => $opt([
                'washer' => 'Paltaryuyan maşın', 'dryer' => 'Qurutma maşını',
                'steam_cabinet' => 'Buxar şkafı', 'steamer' => 'Buxarlayıcı',
                'ironing_fold' => 'Qatlanan ütü lövhəsi', 'ironing_free' => 'Ayrıca duran ütü lövhəsi',
                'robot_vacuum' => 'Robot-tozsoran', 'hand_vacuum' => 'Əl tozsoranı',
                'tv' => 'Televizor', 'speakers' => 'Dinamiklər',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'laundry_other_tech', 'label' => 'Digər texnika', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'laundry_sink', 'label' => 'Raqovina', 'type' => 'multiselect',
            'options' => $opt([
                'small' => 'Kiçik raqovina', 'utility' => 'Təsərrüfat raqovinası',
                'shower_tray' => 'Duş poddonu (ayaqqabı, it yumaq üçün)', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'laundry_storage', 'label' => 'Saxlama', 'type' => 'multiselect',
            'options' => $opt([
                'open_shelving' => 'Açıq stellaj', 'utility_cabinet' => 'Təsərrüfat şkafı',
                'clothes_rail' => 'Geyim üçün ştanq', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'laundry_drying', 'label' => 'Paltarın qurudulması', 'type' => 'multiselect',
            'options' => $opt([
                'wall' => 'Divar qurutması', 'floor' => 'Döşəmə qurutması', 'ceiling' => 'Tavan qurutması',
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'laundry_baskets', 'label' => 'Paltar səbətlərinin sayı', 'type' => 'number',
            'options' => null, 'required' => false, 'delegatable' => false],
        ['key' => 'laundry_towel_warmer', 'label' => 'Dəsmal qurudan', 'type' => 'select',
            'options' => $opt(['electric' => 'Elektrik', 'water' => 'Su', 'designer' => $designer]),
            'required' => false, 'delegatable' => true],
        ['key' => 'laundry_warm_wall', 'label' => 'İsti divar', 'type' => 'select',
            'options' => $opt(['yes' => 'Bəli', 'designer' => $designer]),
            'required' => false, 'delegatable' => true],
        $roomNotes('laundry'),
    ]),

    $roomSection('room_staircase', 'staircase', ['az' => 'Pilləkən', 'ru' => 'Лестница', 'en' => 'Staircase'], [
        ['key' => 'stairs_structure', 'label' => 'Konstruksiya', 'type' => 'select',
            'options' => $opt(['metal_frame' => 'Metal karkas üzərində', 'concrete' => 'Beton', 'designer' => $designer]),
            'required' => false, 'delegatable' => true],
        ['key' => 'stairs_visual', 'label' => 'Vizual effekt', 'type' => 'multiselect',
            'options' => $opt([
                'airy' => 'Yüngül (havadar)', 'massive' => 'Masiv',
                'hidden' => 'Maksimum gizli', 'accent' => 'Aksent', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'stairs_step_material', 'label' => 'Pillələrin materialı', 'type' => 'multiselect',
            'options' => $opt([
                'wood' => 'Ağac / şpon', 'metal' => 'Metal', 'natural_stone' => 'Təbii daş',
                'artificial_stone' => 'Süni daş', 'porcelain' => 'Keramoqranit', 'concrete' => 'Beton',
                'other' => 'Digər', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'stairs_railing_material', 'label' => 'Məhəccərin materialı', 'type' => 'multiselect',
            'options' => $opt([
                'wood' => 'Ağac', 'metal' => 'Metal', 'glass' => 'Şüşə',
                'natural_stone' => 'Təbii daş', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'stairs_safety', 'label' => 'Təhlükəsizlik', 'type' => 'multiselect',
            'options' => $opt([
                'anti_slip' => 'Sürüşməyən örtük', 'no_sharp' => 'İti künclər olmadan',
                'child_safe' => 'Uşaq təhlükəsizliyi', 'soundproof' => 'Gücləndirilmiş səs izolyasiyası',
                'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'stairs_lighting', 'label' => 'Pilləkənin işıqlandırılması', 'type' => 'multiselect',
            'options' => $opt([
                'step_light' => 'Pillələrin podsvetkası', 'ceiling' => 'Pilləkənin üstündə tavan işığı',
                'wall' => 'Divar işıqlandırıcıları', 'combined' => 'Kombinə', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'stairs_spiral_ok', 'label' => 'Vintli pilləkənlər qəbul edilirmi?', 'type' => 'boolean',
            'options' => null, 'required' => false, 'delegatable' => true],
        $roomNotes('stairs'),
    ]),

    $roomSection('room_balcony', 'balcony', ['az' => 'Balkon / loggiya', 'ru' => 'Балкон / лоджия', 'en' => 'Balcony'], [
        ['key' => 'balcony_type', 'label' => 'Balkonun tipi', 'type' => 'select',
            'options' => $opt(['cold' => 'Soyuq', 'warm' => 'İsti']),
            'required' => false, 'delegatable' => true],
        ['key' => 'balcony_purpose', 'label' => 'Təyinatı', 'type' => 'multiselect',
            'options' => $opt([
                'workspace' => 'İş yeri', 'relax' => 'İstirahət yeri',
                'winter_garden' => 'Qış bağının qurulması', 'drying' => 'Paltar qurutmanın qurulması',
                'ac_unit' => 'Kondisioner üçün yer', 'designer' => $designer,
            ]),
            'required' => false, 'delegatable' => true],
        ['key' => 'balcony_furniture', 'label' => 'Mebel', 'type' => 'multiselect',
            'options' => $opt(['cabinet' => 'Şkaf', 'lounger' => 'Şezlonq', 'hanging_chair' => 'Asma kreslo', 'designer' => $designer]),
            'required' => false, 'delegatable' => true],
        $roomNotes('balcony'),
    ]),

    $roomSection('room_other', 'other_room', ['az' => 'Digər otaq', 'ru' => 'Другое помещение', 'en' => 'Other room'], [
        ['key' => 'other_room_name', 'label' => 'Otağın adı', 'type' => 'text',
            'options' => null, 'required' => false, 'delegatable' => false,
            'help' => 'Məsələn: terras, anbar, şərab zirzəmisi, idman zalı.'],
        ['key' => 'other_room_description', 'label' => 'Təsviri', 'type' => 'textarea',
            'options' => null, 'required' => false, 'delegatable' => false],
    ]),

    // ══════════ BÖLMƏ 8 — MÜHƏNDİSLİK (Roomix «Инженерия») ══════════
    [
        'key' => 'engineering',
        'name' => ['az' => 'Mühəndislik', 'ru' => 'Инженерия', 'en' => 'Engineering'],
        'icon' => 'wrench-screwdriver',
        'estimated_minutes' => 4,
        'intro' => ['az' => 'Hansı sistemləri planlaşdırdığınızı işarələyin. Əmin deyilsinizsə «Dizaynerin ixtiyarına» seçin — mütəxəssis özü təklif edəcək.'],
        'questions' => [
            ['key' => 'replanning', 'group' => 'Yenidənplanlaşdırma', 'label' => 'Yenidənplanlaşdırma planlaşdırılır?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => true],

            // Balkon yoxdursa sual ümumiyyətlə açılmır — şərt otaq tərkibindən
            // (7-ci bölmə) gəlir, yəni məntiq bölmə sərhədini keçir.
            ['key' => 'balcony_works', 'group' => 'Balkon və loggiyalar', 'label' => 'Balkonlarda işlər', 'type' => 'multiselect',
                'options' => $opt([
                    'merge' => 'Otaqla birləşdirmək', 'insulate' => 'İstiləşdirmək',
                    'glaze_full' => 'Tam şüşələmək', 'glaze_partial' => 'Qismən şüşələmək',
                    'winter_garden' => 'Qış bağı', 'drying' => 'Paltar qurutma',
                ]),
                'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'room_inventory', 'operator' => 'has_room', 'value' => 'balcony']],

            ['key' => 'heating_type', 'group' => 'İstilik', 'label' => 'İstilik sisteminin tipi', 'type' => 'select',
                'options' => $opt(['central' => 'Mərkəzi', 'individual' => 'Fərdi', 'unknown' => 'Bilmirəm']),
                'required' => false, 'delegatable' => true],
            ['key' => 'heating_zoning', 'group' => 'İstilik', 'label' => 'İstiliyin zonalar üzrə tənzimlənməsi lazımdır?', 'type' => 'select',
                'options' => $opt($yesNoDesigner), 'required' => false, 'delegatable' => true],
            ['key' => 'underfloor_heating', 'group' => 'İstilik', 'label' => 'İsti döşəmə', 'type' => 'select',
                'options' => $opt([
                    'main' => 'Əsas mənbə kimi', 'additional' => 'Əlavə kimi',
                    'tiles_only' => 'Plitə olan yerlərdə', 'none' => 'Lazım deyil', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],

            ['key' => 'radiators_replace', 'group' => 'Radiator və konvektorlar', 'label' => 'Dəyişdirmə', 'type' => 'multiselect',
                'options' => $opt([
                    'radiators' => 'Mövcud radiatorların dəyişdirilməsi',
                    'convectors' => 'Mövcud konvektorların dəyişdirilməsi',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'radiators_type', 'group' => 'Radiator və konvektorlar', 'label' => 'Cihazın tipi', 'type' => 'multiselect',
                'options' => $opt([
                    'wall_radiator' => 'Divar radiatoru', 'floor_radiator' => 'Döşəmə radiatoru',
                    'vertical_radiator' => 'Şaquli radiator', 'in_floor_convector' => 'Döşəmədaxili konvektor',
                    'wall_convector' => 'Divar konvektoru', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'radiators_placement', 'group' => 'Radiator və konvektorlar', 'label' => 'Quraşdırma yeri', 'type' => 'multiselect',
                'options' => $opt([
                    'under_windows' => 'Pəncərələrin altında', 'at_glazing' => 'Vitrajın yanında',
                    'along_wall' => 'Divar boyu', 'in_floor' => 'Döşəmədə', 'in_niche' => 'Nişdə',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'radiators_look', 'group' => 'Radiator və konvektorlar', 'label' => 'Xarici görünüş', 'type' => 'multiselect',
                'options' => $opt([
                    'hidden' => 'Gizli', 'decorative' => 'Dekorativ',
                    'no_accent' => 'Vizual aksentsiz', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'radiators_connection', 'group' => 'Radiator və konvektorlar', 'label' => 'Qoşulma üsulu və termorequlyator', 'type' => 'multiselect',
                'options' => $opt([
                    'from_wall' => 'Divardan qoşulma', 'from_floor' => 'Döşəmədən qoşulma',
                    'thermo_head' => 'Termobaşlıqla', 'wall_thermostat' => 'Divar termorequlyatoru ilə',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],

            ['key' => 'water_heater', 'group' => 'Su təchizatı', 'label' => 'Su qızdırıcısı', 'type' => 'multiselect',
                'options' => $opt([
                    'storage' => 'Yığıcı (bak)', 'instant' => 'Axınlı', 'per_riser' => 'Hər stoyak üçün',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'water_heater_volume', 'group' => 'Su təchizatı', 'label' => 'Yığıcının həcmi, litr', 'type' => 'number',
                'options' => null, 'required' => false, 'delegatable' => true,
                'skip' => ['question' => 'water_heater', 'operator' => 'equals', 'value' => 'storage']],
            ['key' => 'water_nodes', 'group' => 'Su təchizatı', 'label' => 'Mühəndis qovşaqları və suyun hazırlanması', 'type' => 'multiselect',
                'options' => $opt([
                    'meter_node' => 'Su ölçü qovşağı', 'manifold_node' => 'Kollektor qovşağı',
                    'filtration' => 'Filtrasiya və su hazırlığı', 'boiler_room' => 'Qazanxana',
                ]),
                'required' => false, 'delegatable' => true,
                'help' => 'Su ölçü qovşağı — suyun uçotu və reviziya lazımdırsa. Kollektor qovşağı — isti döşəmə (su), radiator paylanması.'],
            ['key' => 'leak_sensors', 'group' => 'Su təchizatı', 'label' => 'Su sızması sensorları', 'type' => 'multiselect',
                'options' => $opt(['water_supply' => 'Su təchizatına', 'heating' => 'İstilik sisteminə']),
                'required' => false, 'delegatable' => true],
            ['key' => 'water_treatment', 'group' => 'Su təchizatı', 'label' => 'Suyun təmizlənməsi və hazırlanması', 'type' => 'multiselect',
                'options' => $opt([
                    'under_sink' => 'Mətbəxdə ayrıca kranla mürəkkəbaltı filtr',
                    'main_line' => 'Bütün mənzil və ya ev üçün magistral filtrasiya',
                    'combined' => 'Kombinə variant', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'water_equipment_place', 'group' => 'Su təchizatı', 'label' => 'Avadanlığın yerləşdirilməsi', 'type' => 'multiselect',
                'options' => $opt([
                    'bathroom' => 'Sanuzel', 'hallway' => 'Dəhliz', 'corridor' => 'Koridor',
                    'utility' => 'Texniki otaq', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'water_equipment_note', 'group' => 'Su təchizatı', 'label' => 'Qeyd', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: mürəkkəbaltı şkafda yer.'],

            ['key' => 'ventilation', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Ventilyasiya', 'type' => 'multiselect',
                'options' => $opt([
                    'natural' => 'Təbii, sistemsiz', 'supply' => 'Təchizedici',
                    'exhaust' => 'Sorucu', 'recuperation' => 'Rekuperasiyalı təchizedici-sorucu',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'air_conditioning', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Kondisionerləşdirmə', 'type' => 'multiselect',
                'options' => $opt([
                    'split' => 'Adi split-sistemlər', 'ducted' => 'Kanal kondisioneri',
                    'multisplit' => 'Multisplit-sistemlər', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'ac_rooms', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Hansı otaqlarda?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: yataq otağı, qonaq otağı, kabinet.'],
            ['key' => 'ac_outdoor_limits', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Xarici blokların yerləşdirilməsi üzrə məhdudiyyətlər', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: yalnız həyət tərəfdən.'],
            ['key' => 'humidification', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Havanın nəmləndirilməsi', 'type' => 'multiselect',
                'options' => $opt([
                    'household' => 'Məişət nəmləndiricisi', 'central' => 'Mərkəzləşdirilmiş sistem',
                    'natural' => 'Təbii rejimi saxlamaq',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'fresh_air_local', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Təmiz havanın təchizi (lokal sistemlər)', 'type' => 'multiselect',
                'options' => $opt([
                    'breezer' => 'Brizer (filtrasiya və qızdırma ilə təmiz hava)',
                    'recuperator' => 'Rekuperator (təchiz və sorma)',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'fresh_air_rooms', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Hansı otaqlarda təmiz hava sistemi?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: yataq otağı, kabinet.'],
            ['key' => 'hvac_equipment_place', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Avadanlığın yerləşdirilməsi', 'type' => 'multiselect',
                'options' => $opt([
                    'utility_outside' => 'Texniki otaq (mənzildən kənar)',
                    'baskets' => 'Daxili və xarici bloklar üçün səbətlər',
                    'loggia' => 'Loggiya', 'storage' => 'Anbar və ya şkaf',
                    'ceiling' => 'Tavanda', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'ceiling_height_limits', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Tavan hündürlüyü üzrə məhdudiyyətlər', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Məsələn: tavan 2,7 m, qutuları gizlətmək.'],
            ['key' => 'microclimate_note', 'group' => 'Mikroiqlim, ventilyasiya, kondisioner', 'label' => 'Mikroiqlim üzrə qeyd', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => false],

            ['key' => 'soundproofing', 'group' => 'Səs izolyasiyası', 'label' => 'Səs izolyasiyası planlaşdırılır?', 'type' => 'select',
                'options' => $opt($yesNoDesigner), 'required' => false, 'delegatable' => true],

            ['key' => 'master_switch', 'group' => 'Elektrik', 'label' => 'Master-düymə', 'type' => 'multiselect',
                'options' => $opt([
                    'indoor_light' => 'Daxili işıqlandırmaya (ev)',
                    'outdoor_light' => 'Xarici işıqlandırmaya (ərazi)',
                    'all_except' => 'Soyuducu, dondurucu, siqnalizasiya, Wi-Fi, istilik, ventilyasiya və sızma sistemindən başqa hər şeyə',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'led_drivers_place', 'group' => 'Elektrik', 'label' => 'Podsvetka qida bloklarının yerləşdirilməsi', 'type' => 'multiselect',
                'options' => $opt(['panel_room' => 'Şitovoyda', 'cabinets' => 'Şkaflarda', 'curtain_niche' => 'Pərdə nişində']),
                'required' => false, 'delegatable' => true],
            ['key' => 'extra_sockets', 'group' => 'Elektrik', 'label' => 'Əlavə rozetka və açarlara ehtiyac', 'type' => 'multiselect',
                'options' => $opt([
                    'usb' => 'USB-rozetkalar', 'charging' => 'Şarj stansiyaları',
                    'internet' => 'İnternet rozetkaları', 'dimmers' => 'Dimmerlər',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'sockets_budget', 'group' => 'Elektrik', 'label' => 'Rozetka və açarlar üzrə büdcə oriyentiri (100 m² mənzil)', 'type' => 'select',
                'options' => $opt([
                    'budget' => 'Büdcə seqmenti', 'mid' => 'Orta', 'premium' => 'Premium', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'sockets_priority', 'group' => 'Elektrik', 'label' => 'Seçimdə nə daha vacibdir?', 'type' => 'multiselect',
                'options' => $opt(['price' => 'Qiymət', 'quality' => 'Keyfiyyət', 'aesthetics' => 'Estetika', 'designer' => $designer]),
                'required' => false, 'delegatable' => true],
            ['key' => 'electric_drive', 'group' => 'Elektrik', 'label' => 'Elektrik ötürücü lazımdır?', 'type' => 'boolean',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: pərdələr, jalüzlər üçün.'],
            ['key' => 'internet_network', 'group' => 'Elektrik', 'label' => 'İnternet və şəbəkələr', 'type' => 'multiselect',
                'options' => $opt([
                    'router' => 'Wi-Fi router', 'lan_sockets' => 'Şəbəkə internet rozetkaları',
                    'mesh' => 'Siqnal gücləndiriciləri, mesh-sistem', 'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'internet_socket_rooms', 'group' => 'Elektrik', 'label' => 'İnternet rozetkaları harada lazımdır?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: kabinet, qonaq otağı.'],

            ['key' => 'security', 'group' => 'Təhlükəsizlik və ağıllı ev', 'label' => 'Təhlükəsizlik', 'type' => 'multiselect',
                'options' => $opt([
                    'video_intercom' => 'Videodomofon', 'intercom_to_tv' => 'Domofonun TV-yə çıxışı',
                    'alarm' => 'Mühafizə siqnalizasiyası', 'fire_alarm' => 'Yanğın siqnalizasiyası',
                    'motion' => 'Hərəkət sensorları', 'cctv' => 'Videomüşahidə',
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'camera_zones', 'group' => 'Təhlükəsizlik və ağıllı ev', 'label' => 'Müşahidə kameraları harada?', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => true,
                'help' => 'Məsələn: giriş, qaraj, həyətyanı sahə.',
                'skip' => ['question' => 'security', 'operator' => 'equals', 'value' => 'cctv']],
            ['key' => 'smart_home', 'group' => 'Təhlükəsizlik və ağıllı ev', 'label' => 'Ağıllı ev', 'type' => 'multiselect',
                'options' => $opt([
                    'smart_speaker' => 'Ağıllı dinamik', 'light' => 'İşığın idarəsi',
                    'curtains' => 'Pərdələrin idarəsi', 'climate' => 'İqlimin idarəsi',
                    'security' => 'Təhlükəsizliyin idarəsi', 'multimedia' => 'Multimedianın idarəsi',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'smart_home_system', 'group' => 'Təhlükəsizlik və ağıllı ev', 'label' => 'Ağıllı ev sistemi', 'type' => 'multiselect',
                'options' => $opt([
                    'centralised' => 'Avadanlıq şkafı ilə mərkəzləşdirilmiş sistem',
                    'partial' => 'Ayrı zonalar üçün qismən həllər',
                    'phone' => 'Telefon və ya planşetlə idarə',
                    'voice' => 'Səslə idarə (Alisa və digərləri)',
                    'designer' => $designer,
                ]),
                'required' => false, 'delegatable' => true],
            ['key' => 'engineering_notes', 'group' => 'Təhlükəsizlik və ağıllı ev', 'label' => 'Mühəndislik üzrə əlavə istəklər', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => false],
        ],
    ],

    // ══════════════ BÖLMƏ 9 — KONTAKTLAR (Roomix «Контакты») ══════════════
    [
        'key' => 'contacts',
        'name' => ['az' => 'Kontaktlar', 'ru' => 'Контакты', 'en' => 'Contacts'],
        'icon' => 'phone',
        'estimated_minutes' => 2,
        'intro' => ['az' => 'Bu məlumatlar texniki tapşırığa daxil olacaq. Ulduzlu sahələr brifin göndərilməsi üçün məcburidir.'],
        'questions' => [
            ['key' => 'contact_full_name', 'group' => 'Əlaqə məlumatları', 'label' => 'Ad və soyad', 'type' => 'text',
                'options' => null, 'required' => true, 'delegatable' => false],
            ['key' => 'contact_phone', 'group' => 'Əlaqə məlumatları', 'label' => 'Telefon', 'type' => 'text',
                'options' => null, 'required' => true, 'delegatable' => false,
                'help' => 'Ölkə kodu ilə, məsələn +994 50 123 45 67.'],
            ['key' => 'contact_email', 'group' => 'Əlaqə məlumatları', 'label' => 'E-poçt', 'type' => 'text',
                'options' => null, 'required' => true, 'delegatable' => false],
            ['key' => 'passport_details', 'group' => 'Əlaqə məlumatları', 'label' => 'Şəxsiyyət vəsiqəsinin məlumatları', 'type' => 'text',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Seriya və nömrə. Yalnız müqavilə üçün lazımdır — brifin göndərilməsini bloklamır.'],
            ['key' => 'special_requests', 'group' => 'Əlaqə məlumatları', 'label' => 'Xüsusi şərtlər və istəklər', 'type' => 'textarea',
                'options' => null, 'required' => false, 'delegatable' => false,
                'help' => 'Dizaynerin nəzərə alması vacib olan hər şey.'],
            ['key' => 'pdpa_consent', 'group' => 'Əlaqə məlumatları', 'label' => 'Şəxsi məlumatlarımın emalına razılıq verirəm', 'type' => 'consent',
                'options' => null, 'required' => true, 'delegatable' => false,
                'help' => 'Cavablarınız yalnız layihənin hazırlanması üçün istifadə olunur və üçüncü tərəflərə verilmir. Razılıq olmadan brif göndərilə bilməz.'],
        ],
    ],
];
