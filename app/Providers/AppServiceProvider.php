<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
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

        // Render paginator links with Bootstrap 5 markup. Laravel 13 defaults the
        // paginator to Tailwind views, which render unstyled against this Bootstrap
        // theme and emit Tailwind SVG arrows that show as empty prev/next buttons.
        Paginator::useBootstrapFive();

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
