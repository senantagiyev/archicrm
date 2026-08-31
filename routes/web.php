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

Route::middleware(['auth:web'])->prefix('staff-chat')->name('staff.chat.')->group(function () {
    Route::get('/{project}/poll', [\App\Http\Controllers\Staff\ChatController::class, 'poll'])->name('poll');
    Route::post('/{project}', [\App\Http\Controllers\Staff\ChatController::class, 'send'])->name('send');
});

require __DIR__.'/portal.php';
