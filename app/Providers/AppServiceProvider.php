<?php

namespace App\Providers;

use App\Services\Banks\BankManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BankManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RequestException::dontTruncate();
    }
}
