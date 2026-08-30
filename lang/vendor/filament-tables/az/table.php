<?php

/**
 * Filament's Azerbaijani table.php is missing several keys that newer versions added.
 * APP_FALLBACK_LOCALE is `az`, so those keys have nowhere to fall back to and leak raw
 * into the admin panel — "filament-tables::table.result_count" was visible under every
 * table, and the boolean icon columns exposed the raw key as their aria-label.
 * Only the missing keys are listed here; Laravel merges this over the package file.
 */

return [
    'loading' => 'Yüklənir...',

    'default_model_label' => 'qeyd',

    'result_count' => '{0} Nəticə yoxdur|{1} :count nəticə|[2,*] :count nəticə',

    'column_manager' => [
        'actions' => [
            'apply' => [
                'label' => 'Sütunları tətbiq et',
            ],

            'reorder' => [
                'label' => 'Sütunu yenidən sırala',
            ],

            'reset' => [
                'label' => 'Sıfırla',
            ],
        ],
    ],

    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Bəli',
                'false' => 'Xeyr',
            ],
        ],

        'select' => [
            'loading_message' => 'Yüklənir...',
            'no_options_message' => 'Seçim yoxdur.',
            'no_search_results_message' => 'Axtarışa uyğun seçim tapılmadı.',
            'placeholder' => 'Seçim edin',
            'searching_message' => 'Axtarılır...',
            'search_prompt' => 'Axtarmaq üçün yazın...',
        ],
    ],

    'actions' => [
        'reorder_record' => [
            'label' => ':key elementini yenidən sırala',
        ],

        'toggle_record_content' => [
            'label' => ':key elementini aç/bağla',
        ],
    ],

    'filters' => [
        'select' => [
            'relationship' => [
                'empty_option_label' => 'Yoxdur',
            ],
        ],
    ],
];
