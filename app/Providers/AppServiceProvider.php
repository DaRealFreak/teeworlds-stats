<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Schema::defaultStringLength(191);

        // Force https scheme for all generated URLs when APP_URL is https.
        // Behind DDEV's TLS-terminating proxy, Laravel sees plain http internally;
        // without this, @vite and asset() emit http:// URLs that browsers block as
        // mixed content on the https page. Guarded by APP_URL so tests (http/unset)
        // are unaffected.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
