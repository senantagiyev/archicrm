<?php

use App\Http\Controllers\Portal\ApprovalController;
use App\Http\Controllers\Portal\AuthController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\PaymentController;
use App\Http\Controllers\Portal\ProjectController;
use Illuminate\Support\Facades\Route;

Route::prefix('portal')->name('portal.')->group(function () {
    // Guest (magic-link) auth.
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login-link', [AuthController::class, 'sendLoginLink'])
        ->middleware('throttle:5,1')
        ->name('login-link');
    Route::get('/magic/{clientUser}', [AuthController::class, 'magicLogin'])
        ->middleware('signed')
        ->name('magic-login');

    Route::middleware('auth:customer')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/', [ProjectController::class, 'index'])->name('home');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');

        Route::get('/projects/{project}/documents', [DocumentController::class, 'index'])->name('documents');
        Route::get('/projects/{project}/documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

        Route::get('/projects/{project}/payments', [PaymentController::class, 'index'])->name('payments');

        Route::get('/projects/{project}/approvals', [ApprovalController::class, 'index'])->name('approvals');
        Route::post('/approvals/{approval}/decide', [ApprovalController::class, 'decide'])->name('approvals.decide');
    });
});
