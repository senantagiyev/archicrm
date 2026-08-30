<?php

/**
 * Filament ships an Azerbaijani layout.php that is missing a handful of newer keys.
 * APP_FALLBACK_LOCALE is `az`, so a key absent from az has nowhere to fall back to and
 * renders raw (e.g. "filament-panels::layout.skip_to_content.label") in the admin panel.
 * Only the missing keys are listed here; Laravel merges this over the package file.
 */

return [
    'skip_to_content' => [
        'label' => 'Məzmuna keç',
    ],

    'actions' => [
        'open_database_notifications' => [
            'label_with_unread_count' => '{1} Bildirişlər, :count oxunmamış bildiriş|[2,*] Bildirişlər, :count oxunmamış bildiriş',
        ],

        'theme_switcher' => [
            'label' => 'Mövzu',
        ],
    ],

    'navigation' => [
        'label' => 'Yan menyu',
    ],

    'topbar' => [
        'label' => 'Üst panel',
    ],

    'tenant_menu' => [
        'search_field' => [
            'label' => 'Arananı seç',
            'placeholder' => 'Axtar',
        ],
    ],
];
