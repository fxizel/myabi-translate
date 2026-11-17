<?php

namespace App\Providers;

use App\Services\CatalogueSearch\IndexStatus;
use App\Services\CatalogueSearch\Profile;
use App\Services\OperationLock;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Profile::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.app', function ($view) {
            if (auth()->check()) {
                $status = app(IndexStatus::class)->check();
                if ($status === IndexStatus::NOT_PREPARED && request()->routeIs('terms.index')) {
                    $view->with('catalogueSearchWarning', 'search.index_not_initialized');
                } elseif ($status === IndexStatus::UNAVAILABLE) {
                    $view->with('catalogueSearchWarning', 'search.service_unavailable');
                }
            }
        });
        View::composer('*', function ($view) {
            $user = auth()->user();
            $view->with('canManage', $user?->canManage() ?? false)->with('canValidate', $user?->canValidate() ?? false)
                ->with('canTranslate', $user?->canTranslate() ?? false)->with('canAdmin', $user?->canAdmin() ?? false)
                ->with('writesPaused', app(OperationLock::class)->busy());
        });
    }
}
