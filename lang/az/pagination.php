<?php

/*
 * Laravel's paginator views resolve pagination.previous / pagination.next through
 * __(). The app replaces the translation loader with DatabaseTranslationLoader,
 * which is constructed with lang_path() only — the framework's own lang folder is
 * not in the loader's search path — so without this file the raw keys were printed
 * in the paginator's mobile (sm:hidden) links. The DB "translations" table may
 * still override the group from the Filament module.
 */

return [
    'previous' => '&laquo; Əvvəlki',
    'next' => 'Növbəti &raquo;',
];
