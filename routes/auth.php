<?php

use App\Http\Controllers\AdminAuditController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthInvitationController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureSecureSession;
use App\Http\Middleware\EnsureValidTwoFactorChallenge;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\RequireTwoFactor;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

Route::post('/locale', [ProfileController::class, 'locale'])->middleware(EnsureSecureSession::class)->name('profile.locale');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.update');
    Route::get('/invitations/{token}', [AuthInvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitations/{token}', [AuthInvitationController::class, 'accept'])->middleware('throttle:password-reset')->name('invitation.accept');
    Route::middleware(EnsureValidTwoFactorChallenge::class)->group(function () {
        Route::get('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'create'])->name('two-factor.login');
        Route::post('/two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])->middleware('throttle:two-factor')->name('two-factor.login.store');
    });
});
Route::middleware(['auth', EnsureSecureSession::class, RequireTwoFactor::class])->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/user/confirm-password', [ConfirmablePasswordController::class, 'show'])->name('password.confirm');
    Route::post('/user/confirm-password', [ConfirmablePasswordController::class, 'store'])->middleware('throttle:password-reset')->name('password.confirm.store');
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:password-reset')->name('profile.password.update');
    Route::post('/profile/two-factor', [ProfileController::class, 'enableTwoFactor'])->middleware('throttle:password-reset')->name('profile.two-factor.enable');
    Route::post('/profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->middleware('throttle:password-reset')->name('profile.two-factor.confirm');
    Route::delete('/profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->middleware('throttle:password-reset')->name('profile.two-factor.disable');
    Route::middleware(RequireRole::class.':admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
        Route::patch('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
        Route::post('/users/{user}/activate', [AdminController::class, 'activateUser'])->middleware('throttle:password-reset')->name('users.activate');
        Route::post('/users/{user}/revoke', [AdminController::class, 'revokeSessions'])->name('users.revoke');
        Route::post('/users/{user}/unlock', [AdminController::class, 'unlock'])->name('users.unlock');
        Route::post('/users/{user}/invite', [AdminController::class, 'invite'])->name('users.invite');
        Route::post('/organisations', [AdminController::class, 'storeOrganisation'])->name('organisations.store');
        Route::patch('/organisations/{organisation}', [AdminController::class, 'updateOrganisation'])->name('organisations.update');
    });
    Route::middleware(RequireRole::class.':audit')->group(function () {
        Route::get('/audit', [AdminAuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AdminAuditController::class, 'export'])->name('audit.export');
    });
});
