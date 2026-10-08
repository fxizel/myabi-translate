<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\PublicationController;
use App\Http\Controllers\TermController;
use App\Http\Controllers\VersionController;
use Illuminate\Support\Facades\Route;

require __DIR__.'/auth.php';

Route::middleware(['auth', 'session.secure', '2fa.required'])->group(function () {
    Route::get('/', [TermController::class, 'dashboard'])->name('dashboard');
    Route::get('/terms', [TermController::class, 'index'])->name('terms.index');
    Route::get('/terms/{term}', [TermController::class, 'show'])->name('terms.show');
    Route::post('/terms/{term}/scope', [TermController::class, 'scope'])->name('terms.scope');
    Route::post('/terms/{term}/proposals', [ProposalController::class, 'store'])->name('proposals.store');
    Route::post('/terms/{term}/confirm', [ProposalController::class, 'confirm'])->name('terms.confirm');
    Route::get('/validation', [ProposalController::class, 'index'])->name('validation.index');
    Route::patch('/proposals/{proposal}', [ProposalController::class, 'update'])->name('proposals.update');
    Route::post('/proposals/bulk-store', [ProposalController::class, 'bulkStore'])->name('proposals.bulk-store');
    Route::post('/proposals/bulk', [ProposalController::class, 'bulk'])->name('proposals.bulk');
    Route::post('/proposals/validate-filtered', [ProposalController::class, 'validateFiltered'])->name('proposals.validate-filtered');
    Route::post('/proposals/filtered/{operation}/retry', [ProposalController::class, 'retryFiltered'])->name('proposals.filtered-retry');
    Route::post('/proposals/{proposal}/decide', [ProposalController::class, 'decide'])->name('proposals.decide');
    Route::middleware('role:manager')->group(function () {
        Route::get('/exports', [ExportController::class, 'index'])->name('exports.index');
        Route::post('/exports/download', [ExportController::class, 'download'])->name('exports.download');
        Route::get('/imports', [ImportController::class, 'index'])->name('imports.index');
        Route::post('/imports', [ImportController::class, 'store'])->name('imports.store');
        Route::get('/imports/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::get('/imports/{import}/progress', [ImportController::class, 'progress'])->name('imports.progress');
        Route::get('/imports/{import}/original', [ImportController::class, 'original'])->name('imports.original');
        Route::get('/imports/{import}/report', [ImportController::class, 'report'])->name('imports.report');
        Route::post('/imports/{import}/apply', [ImportController::class, 'apply'])->name('imports.apply');
        Route::post('/imports/{import}/cancel', [ImportController::class, 'cancel'])->name('imports.cancel');
        Route::post('/imports/{import}/retry', [ImportController::class, 'retry'])->name('imports.retry');
        Route::post('/imports/{import}/validate', [ImportController::class, 'validateInitial'])->name('imports.validate');
        Route::get('/imports/{import}/validation-preview', [ImportController::class, 'validationPreview'])->name('imports.validation-preview');
        Route::post('/imports/{import}/validation-preview', [ImportController::class, 'validationPreview'])->name('imports.validation-preview.queue');
        Route::post('/imports/{import}/validation-operations/{operation}/retry', [ImportController::class, 'retryInitial'])->name('imports.validation-retry');
    });
    Route::get('/publications', [PublicationController::class, 'index'])->name('publications.index');
    Route::post('/publications', [PublicationController::class, 'store'])->name('publications.store');
    Route::get('/publications/{publication}', [PublicationController::class, 'show'])->name('publications.show');
    Route::get('/publications/{publication}/download', [PublicationController::class, 'download'])->name('publications.download');
    Route::post('/publications/{publication}/withdraw', [PublicationController::class, 'withdraw'])->name('publications.withdraw');
    Route::post('/publications/{publication}/retry', [PublicationController::class, 'retry'])->name('publications.retry');
    Route::post('/versions', [VersionController::class, 'store'])->name('versions.store');
    Route::patch('/versions/{version}', [VersionController::class, 'update'])->name('versions.update');
    Route::view('/help', 'help')->name('help');
});
