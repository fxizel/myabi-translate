<?php

namespace App\Providers;

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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('*', function ($view) {
            $user = auth()->user();
            $view->with('canManage', $user?->canManage() ?? false)->with('canValidate', $user?->canValidate() ?? false)
                ->with('canTranslate', $user?->canTranslate() ?? false)->with('canAdmin', $user?->canAdmin() ?? false)
                ->with('writesPaused', app(OperationLock::class)->busy());
        });
    }
}
