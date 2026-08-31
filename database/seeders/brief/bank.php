<?php

/**
 * Brif sual bankı — TZ Blok 2.2 strukturu: Sizin haqqınızda → Obyekt → Estetika
 * → Komplektasiya → Bəzək materialları → İşıqlandırma → Otaqlar → Mühəndis
 * sistemləri → Əlaqələr. Sualların dərinliyi/sırası saxlanılır (TZ Əlavə);
 * məzmun Roomix skrinşotlarından (PDF) mərhələli genişləndirilir.
 *
 * Format: hər bölmə üçün key, name{az,ru,en}, room_type, questions[].
 * Sual: [key, label_az, type, options|null, required, allows_designer_choice]
 * options: [value => label_az]
 */
$opt = fn (array $pairs) => collect($pairs)
    ->map(fn ($label, $value) => ['value' => $value, 'label' => ['az' => $label]])
    ->values()
    ->all();

$roomBase = fn () => [
    ['purpose', 'Bu otaqdan əsasən kim və necə istifadə edəcək?', 'textarea', null, true, false],
    ['style_wish', 'Bu otaq üçün üslub istəyiniz', 'text', null, false, true],
    ['color_mood', 'Rəng ovqatı', 'select', $opt(['light' => 'Açıq və işıqlı', 'warm' => 'İsti tonlar', 'dark' => 'Tünd və dramatik', 'neutral' => 'Neytral']), false, true],
    ['storage', 'Saxlama ehtiyacları (şkaflar, rəflər)', 'textarea', null, false, true],
    ['keep_furniture', 'Saxlanılacaq mövcud mebel varmı?', 'textarea', null, false, false],
    ['special', 'Xüsusi istəklər', 'textarea', null, false, false],
];

return [
    [
        'key' => 'about_you',
        'name' => ['az' => 'Sizin haqqınızda', 'ru' => 'О вас', 'en' => 'About you'],
        'icon' => 'user',
        'questions' => [
            ['household', 'Evdə kimlər yaşayacaq? (yaş qrupları ilə)', 'textarea', null, true, false],
            ['pets', 'Ev heyvanlarınız varmı?', 'text', null, false, false],
            ['lifestyle', 'Gündəlik həyat tərziniz necədir? (evdə iş, qonaqpərvərlik, səyahət...)', 'textarea', null, false, false],
            ['hobbies', 'Hobbiləriniz və evdə xüsusi yer tələb edən məşğuliyyətlər', 'textarea', null, false, false],
            ['daily_rhythm', 'Ailənin gün rejimi (erkən/gec qalxma, evdə iş)', 'text', null, false, false],
        ],
    ],
    [
        'key' => 'object',
        'name' => ['az' => 'Obyekt', 'ru' => 'Объект', 'en' => 'The property'],
        'icon' => 'home',
        'questions' => [
            ['condition', 'Obyektin hazırkı vəziyyəti', 'select', $opt(['new' => 'Yeni tikili (qara çərçivə)', 'repair' => 'Təmirli, yenilənəcək', 'old' => 'Köhnə fond']), true, false],
            ['floor_info', 'Mərtəbə və bina haqqında məlumat', 'text', null, false, false],
            ['walls_change', 'Divarların sökülməsi/dəyişdirilməsi planlaşdırılırmı?', 'boolean', null, false, true],
            ['balcony', 'Balkon/terras necə istifadə olunacaq?', 'textarea', null, false, true],
            ['problems', 'Bildiyiniz problemlər (rütubət, səs, işıq çatışmazlığı...)', 'textarea', null, false, false],
        ],
    ],
    [
        'key' => 'aesthetics',
        'name' => ['az' => 'Estetika', 'ru' => 'Эстетика', 'en' => 'Aesthetics'],
        'icon' => 'sparkles',
        'questions' => [
            ['style', 'Hansı üslub(lar) sizə yaxındır?', 'multiselect', $opt(['modern' => 'Müasir / minimalizm', 'classic' => 'Klassika', 'loft' => 'Loft', 'scandi' => 'Skandinav', 'japandi' => 'Japandi', 'eclectic' => 'Eklektika']), true, true],
            ['colors_like', 'Sevdiyiniz rənglər', 'text', null, false, true],
            ['colors_avoid', 'İstəmədiyiniz rənglər', 'text', null, false, false],
            ['references', 'Bəyəndiyiniz interyer nümunələri (linklər)', 'textarea', null, false, false],
            ['atmosphere', 'Evinizdə hansı atmosferi istəyirsiniz?', 'select', $opt(['cozy' => 'Rahat və isti', 'elegant' => 'Zərif və təmtəraqlı', 'fresh' => 'Təmiz və minimal', 'bold' => 'Cəsarətli və fərqli']), false, true],
        ],
    ],
    [
        'key' => 'procurement',
        'name' => ['az' => 'Komplektasiya', 'ru' => 'Комплектация', 'en' => 'Procurement'],
        'icon' => 'shopping-bag',
        'questions' => [
            ['budget_range', 'Mebel və avadanlıq üçün büdcə aralığı', 'select', $opt(['economy' => 'Ekonom', 'medium' => 'Orta', 'premium' => 'Premium', 'mixed' => 'Qarışıq — vacib yerlərə premium']), true, true],
            ['purchase_help', 'Satınalmalarda büronun köməyi lazımdırmı?', 'select', $opt(['full' => 'Tam — hər şeyi büro alsın', 'partial' => 'Qismən', 'no' => 'Xeyr, özümüz alacağıq']), true, false],
            ['brands', 'Üstünlük verdiyiniz brendlər/mağazalar', 'text', null, false, true],
            ['custom_furniture', 'Sifarişlə mebel hazırlanmasına münasibətiniz', 'select', $opt(['yes' => 'Bəli, istəyirik', 'maybe' => 'Lazım olsa', 'no' => 'Yalnız hazır mebel']), false, true],
        ],
    ],
    [
        'key' => 'finish_materials',
        'name' => ['az' => 'Bəzək materialları', 'ru' => 'Отделочные материалы', 'en' => 'Finish materials'],
        'icon' => 'swatch',
        'questions' => [
            ['floor', 'Döşəmə üçün üstünlükləriniz', 'multiselect', $opt(['parquet' => 'Parket / laminat', 'tile' => 'Kafel / keramoqranit', 'stone' => 'Təbii daş', 'carpet' => 'Xalça örtüyü']), false, true],
            ['walls', 'Divar örtükləri', 'multiselect', $opt(['paint' => 'Boya', 'wallpaper' => 'Divar kağızı', 'plaster' => 'Dekorativ suvaq', 'panels' => 'Panellər / ağac']), false, true],
            ['ceiling', 'Tavan həlli', 'select', $opt(['flat' => 'Düz boyalı', 'stretch' => 'Dartma tavan', 'gypsum' => 'Gips karton konstruksiyalar']), false, true],
            ['eco', 'Ekoloji/təbii materiallar prioritetdirmi?', 'boolean', null, false, false],
            ['avoid_materials', 'İstifadəsini istəmədiyiniz materiallar', 'text', null, false, false],
        ],
    ],
    [
        'key' => 'lighting',
        'name' => ['az' => 'İşıqlandırma', 'ru' => 'Освещение', 'en' => 'Lighting'],
        'icon' => 'light-bulb',
        'questions' => [
            ['temperature', 'İşığın rəng temperaturu', 'select', $opt(['warm' => 'İsti (2700–3000K)', 'neutral' => 'Neytral (3500–4000K)', 'cold' => 'Soyuq (5000K)', 'mixed' => 'Ssenariyə görə qarışıq']), false, true],
            ['scenarios', 'İşıq ssenariləri (əsas, fon, aksent) vacibdirmi?', 'boolean', null, false, true],
            ['smart_light', 'Ağıllı işıqlandırma istəyirsinizmi?', 'boolean', null, false, true],
            ['decorative', 'Dekorativ işıqlandırmaya münasibət (çilçıraq, bra, LED lentlər)', 'textarea', null, false, true],
        ],
    ],
    [
        'key' => 'rooms_hub',
        'name' => ['az' => 'Otaqlar', 'ru' => 'Комнаты', 'en' => 'Rooms'],
        'icon' => 'squares-2x2',
        'questions' => [
            ['rooms_note', 'Otaqlar barədə ümumi qeydləriniz', 'textarea', null, false, false],
        ],
    ],
    [
        'key' => 'room_living',
        'name' => ['az' => 'Qonaq otağı', 'ru' => 'Гостиная', 'en' => 'Living room'],
        'room_type' => 'living',
        'questions' => array_merge($roomBase(), [
            ['tv_zone', 'TV/media zonası lazımdırmı?', 'boolean', null, false, false],
            ['guests', 'Qonaq qəbulu üçün nə qədər oturacaq yeri?', 'text', null, false, true],
        ]),
    ],
    [
        'key' => 'room_kitchen',
        'name' => ['az' => 'Mətbəx', 'ru' => 'Кухня', 'en' => 'Kitchen'],
        'room_type' => 'kitchen',
        'questions' => array_merge($roomBase(), [
            ['cooking_freq', 'Nə qədər tez-tez yemək bişirilir?', 'select', $opt(['daily' => 'Hər gün', 'often' => 'Tez-tez', 'rare' => 'Nadir hallarda']), false, false],
            ['appliances', 'Lazım olan texnika siyahısı', 'textarea', null, false, true],
            ['island', 'Mətbəx adası istəyirsinizmi?', 'boolean', null, false, true],
        ]),
    ],
    [
        'key' => 'room_bedroom',
        'name' => ['az' => 'Yataq otağı', 'ru' => 'Спальня', 'en' => 'Bedroom'],
        'room_type' => 'bedroom',
        'questions' => array_merge($roomBase(), [
            ['bed_size', 'Çarpayı ölçüsü', 'select', $opt(['160' => '160 sm', '180' => '180 sm', '200' => '200 sm']), false, true],
            ['wardrobe', 'Qarderob otağı yoxsa şkaf?', 'select', $opt(['walkin' => 'Qarderob otağı', 'wardrobe' => 'Şkaf', 'both' => 'Hər ikisi']), false, true],
        ]),
    ],
    [
        'key' => 'room_kids',
        'name' => ['az' => 'Uşaq otağı', 'ru' => 'Детская', 'en' => 'Kids room'],
        'room_type' => 'kids',
        'questions' => array_merge($roomBase(), [
            ['kid_age', 'Uşağın yaşı və cinsi', 'text', null, true, false],
            ['grow_plan', 'Otaq böyüdükcə dəyişməli olacaqmı? (5+ il perspektivi)', 'boolean', null, false, true],
        ]),
    ],
    [
        'key' => 'room_bathroom',
        'name' => ['az' => 'Sanitar qovşaq', 'ru' => 'Санузел', 'en' => 'Bathroom'],
        'room_type' => 'bathroom',
        'questions' => array_merge($roomBase(), [
            ['bath_or_shower', 'Vanna yoxsa duş?', 'select', $opt(['bath' => 'Vanna', 'shower' => 'Duş', 'both' => 'Hər ikisi']), false, true],
            ['laundry', 'Paltaryuyan bu otaqda yerləşəcəkmi?', 'boolean', null, false, false],
        ]),
    ],
    [
        'key' => 'room_hallway',
        'name' => ['az' => 'Dəhliz', 'ru' => 'Прихожая', 'en' => 'Hallway'],
        'room_type' => 'hallway',
        'questions' => $roomBase(),
    ],
    [
        'key' => 'engineering',
        'name' => ['az' => 'Mühəndis sistemləri', 'ru' => 'Инженерные системы', 'en' => 'Engineering systems'],
        'icon' => 'wrench-screwdriver',
        'questions' => [
            ['climate', 'İqlim həlli', 'multiselect', $opt(['ac' => 'Kondisioner', 'floor_heat' => 'İsti döşəmə', 'radiator' => 'Radiator', 'ventilation' => 'Məcburi ventilyasiya']), false, true],
            ['smart_home', 'Ağıllı ev sistemləri', 'multiselect', $opt(['light' => 'İşıq', 'climate' => 'İqlim', 'security' => 'Təhlükəsizlik', 'curtains' => 'Pərdələr', 'none' => 'Lazım deyil']), false, true],
            ['water', 'Su təmizləmə/filtrasiya lazımdırmı?', 'boolean', null, false, true],
            ['electrics_note', 'Elektrik üzrə xüsusi tələblər (rozetkaların yeri və s.)', 'textarea', null, false, true],
        ],
    ],
    [
        'key' => 'contacts',
        'name' => ['az' => 'Əlaqə məlumatları', 'ru' => 'Контакты', 'en' => 'Contacts'],
        'icon' => 'phone',
        'questions' => [
            ['contact_person', 'Layihə üzrə əsas əlaqə şəxsi', 'text', null, true, false],
            ['contact_phone', 'Telefon', 'text', null, true, false],
            ['contact_time', 'Əlaqə üçün rahat vaxt', 'text', null, false, false],
            ['decision_maker', 'Yekun qərarları kim verir?', 'text', null, false, false],
        ],
    ],
];
