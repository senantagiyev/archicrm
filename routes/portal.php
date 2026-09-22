<?php

use App\Http\Controllers\Portal\ApprovalController;
use App\Http\Controllers\Portal\AuthController;
use App\Http\Controllers\Portal\BriefController;
use App\Http\Controllers\Portal\ChatController;
use App\Http\Controllers\Portal\DiaryController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\EstimateController;
use App\Http\Controllers\Portal\FileController;
use App\Http\Controllers\Portal\GlobalApprovalController;
use App\Http\Controllers\Portal\GlobalDocumentController;
use App\Http\Controllers\Portal\NotificationController;
use App\Http\Controllers\Portal\PaymentController;
use App\Http\Controllers\Portal\ProcurementController;
use App\Http\Controllers\Portal\ProfileController;
use App\Http\Controllers\Portal\ProjectController;
use App\Http\Controllers\Portal\StageController;
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

        // Global left-nav hub: everything the customer has, across all projects.
        // Read-only listings, so the read limiter applies.
        Route::middleware('throttle:portal-read')->group(function () {
            Route::get('/approvals', [GlobalApprovalController::class, 'index'])->name('approvals.all');
            Route::get('/documents', [GlobalDocumentController::class, 'index'])->name('documents.all');
            Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        });
        // «Hamısını oxunmuş et» — yazan əməliyyatdır, ona görə oxu qrupunun
        // xaricində, öz `portal-write` limiti ilə.
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->middleware('throttle:portal-write')->name('notifications.read-all');

        Route::get('/projects/{project}/brief', [BriefController::class, 'index'])->name('brief');
        // Layihə bölmələri — Roomix-dəki tab dəsti.
        Route::get('/projects/{project}/stages', [StageController::class, 'index'])->name('stages');
        Route::get('/projects/{project}/files', [FileController::class, 'index'])->name('files');
        Route::get('/projects/{project}/files/{file}/download', [FileController::class, 'download'])->name('files.download');
        Route::get('/projects/{project}/diary', [DiaryController::class, 'index'])->name('diary');
        // Foto birbaşa public disk linki ilə verilsəydi, linki bilən kənar şəxs
        // onu sessiyasız aça bilərdi — ona görə avtorizasiyalı marşrutdan keçir.
        Route::get('/projects/{project}/diary/{entry}/photo/{index}', [DiaryController::class, 'photo'])
            ->whereNumber('index')->middleware('throttle:portal-read')->name('diary.photo');
        // Screen 11 — must be declared before the {section} catch-all below.
        Route::get('/projects/{project}/brief/summary', [BriefController::class, 'summary'])->name('brief.summary');
        Route::get('/projects/{project}/brief/sent', [BriefController::class, 'sent'])->name('brief.sent');
        Route::get('/projects/{project}/brief/clarifications', [BriefController::class, 'clarifications'])->name('brief.clarifications');
        Route::post('/projects/{project}/brief-clarifications', [BriefController::class, 'sendClarifications'])
            ->middleware('throttle:portal-write')->name('brief.clarifications.send');
        Route::get('/projects/{project}/brief/{section}/{room?}', [BriefController::class, 'section'])->name('brief.section');

        Route::middleware('throttle:portal-write')->group(function () {
            Route::post('/projects/{project}/brief-submit/{section}', [BriefController::class, 'submit'])->name('brief.submit');
            Route::post('/projects/{project}/brief-upload/{section}', [BriefController::class, 'upload'])->name('brief.upload');
            Route::post('/projects/{project}/brief-send', [BriefController::class, 'submitBrief'])->name('brief.send');
            // Brifin içindən dizaynerə sual — cavabı dəyişmir, çata mesaj atır.
            Route::post('/projects/{project}/brief-discuss/{section}', [BriefController::class, 'discuss'])->name('brief.discuss');
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

        Route::get('/projects/{project}/estimate', [EstimateController::class, 'index'])->name('estimate');
        Route::get('/projects/{project}/estimate/export', [EstimateController::class, 'export'])
            ->middleware('throttle:portal-read')->name('estimate.export');

        Route::get('/projects/{project}/approvals', [ApprovalController::class, 'index'])->name('approvals');

        // Komplektasiya siyahısı — səhifə, CSV ixracı və avtorizasiyalı foto.
        Route::get('/projects/{project}/procurement', [ProcurementController::class, 'index'])->middleware('throttle:portal-read')->name('procurement');
        Route::get('/projects/{project}/procurement/export', [ProcurementController::class, 'export'])->middleware('throttle:portal-read')->name('procurement.export');
        Route::get('/projects/{project}/procurement/{item}/photo', [ProcurementController::class, 'photo'])->whereNumber('item')->middleware('throttle:portal-read')->name('procurement.photo');

        // Polling endpoints — generous read limit.
        Route::middleware('throttle:portal-read')->group(function () {
            Route::get('/chat-unread', [ChatController::class, 'unread'])->name('chat.unread');
            Route::get('/projects/{project}/chat', [ChatController::class, 'index'])->name('chat');
            // Əlavə birbaşa `public` disk linki ilə verilsəydi, linki bilən kənar
            // şəxs onu sessiyasız aça bilərdi — ona görə avtorizasiyalı marşrut.
            Route::get('/projects/{project}/chat/attachment/{message}', [ChatController::class, 'download'])
                ->whereNumber('message')->name('chat.attachment');
            Route::get('/projects/{project}/chat/poll', [ChatController::class, 'poll'])->name('chat.poll');
        });
    });
});
