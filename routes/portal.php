<?php

use App\Http\Controllers\Portal\ApprovalController;
use App\Http\Controllers\Portal\AuthController;
use App\Http\Controllers\Portal\BriefController;
use App\Http\Controllers\Portal\ChatController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\PaymentController;
use App\Http\Controllers\Portal\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->group(function () {
    // Guest (magic-link) auth — strict per-IP throttling against brute force.
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login-link', [AuthController::class, 'sendLoginLink'])
        ->middleware('throttle:auth')
        ->name('login-link');
    Route::get('/magic/{clientUser}', [AuthController::class, 'magicLogin'])
        ->middleware(['signed', 'throttle:auth'])
        ->name('magic-login');

    Route::middleware('auth:customer')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->middleware('throttle:portal-write')->name('logout');

        Route::get('/', [ProjectController::class, 'index'])->name('home');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

        Route::get('/projects/{project}/brief', [BriefController::class, 'index'])->name('brief');
        Route::get('/projects/{project}/brief/{section}/{room?}', [BriefController::class, 'section'])->name('brief.section');

        Route::middleware('throttle:portal-write')->group(function () {
            Route::post('/projects/{project}/brief/rooms', [BriefController::class, 'addRoom'])->name('brief.rooms.add');
            Route::post('/projects/{project}/brief-submit/{section}', [BriefController::class, 'submit'])->name('brief.submit');
            Route::post('/projects/{project}/chat', [ChatController::class, 'send'])->name('chat.send');
            Route::post('/approvals/{approval}/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
        });

        // Debounced, idempotent — higher ceiling so fast typing is never blocked.
        Route::patch('/projects/{project}/brief-autosave/{section}', [BriefController::class, 'autosave'])
            ->middleware('throttle:portal-autosave')->name('brief.autosave');

        Route::get('/projects/{project}/documents', [DocumentController::class, 'index'])->name('documents');
        Route::get('/projects/{project}/documents/{document}/download', [DocumentController::class, 'download'])
            ->middleware('throttle:portal-read')->name('documents.download');

        Route::get('/projects/{project}/payments', [PaymentController::class, 'index'])->name('payments');

        Route::get('/projects/{project}/approvals', [ApprovalController::class, 'index'])->name('approvals');

        // Polling endpoints — generous read limit.
        Route::middleware('throttle:portal-read')->group(function () {
            Route::get('/chat-unread', [ChatController::class, 'unread'])->name('chat.unread');
            Route::get('/projects/{project}/chat', [ChatController::class, 'index'])->name('chat');
            Route::get('/projects/{project}/chat/poll', [ChatController::class, 'poll'])->name('chat.poll');
        });
    });
});
