<?php

namespace App\Providers;

use App\Services\CatalogueSearch\Profile;
use App\Services\OperationLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            if (auth()->check() && Schema::hasTable('catalogue_search_state')) {
                $state = DB::table('catalogue_search_state')->where('id', 1)->first();
                $view->with('catalogueSearchUnavailable', $state?->references_ready && (! $state->ready
                    || (int) $state->normalization_version !== Profile::VERSION
                    || DB::table('catalogue_search_dirty')->exists()));
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
