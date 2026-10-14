<?php

use App\Http\Controllers\BrowserExtensionController;
use App\Http\Middleware\AuthenticateBrowserExtensionToken;
use App\Http\Middleware\ThrottleBrowserExtension;
use Illuminate\Support\Facades\Route;

Route::prefix('browser-extension')->middleware([
    AuthenticateBrowserExtensionToken::class,
    ThrottleBrowserExtension::class,
])->group(function () {
    Route::post('/search', [BrowserExtensionController::class, 'search'])->name('browser-extension.search');
    Route::post('/feedback', [BrowserExtensionController::class, 'feedback'])->name('browser-extension.feedback');
});
