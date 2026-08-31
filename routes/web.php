<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/app'));

Route::post('/locale', function () {
    $locale = request()->string('locale')->toString();

    if (in_array($locale, ['az', 'ru', 'en'], true)) {
        session(['locale' => $locale]);
    }

    return back();
})->name('locale.switch');

require __DIR__.'/portal.php';
