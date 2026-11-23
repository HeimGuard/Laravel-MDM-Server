<?php

namespace App\Providers;

use App\Support\PortalUrl;
use Illuminate\Support\ServiceProvider;
use URL;

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
        // Without APP_SYSTEM_ADMINS the first user (created on the setup page) is the system admin.
        if (array_filter(config('boilerplate.system_admins', [])) === []) {
            config(['boilerplate.system_admins' => ['1']]);
        }

        if (config('app.env') != 'local' && ! $this->openedDirectly()) {
            URL::forceScheme('https');
        }
        // The scheduler (notifications) links to the address the portal is opened with.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            PortalUrl::apply();
        }
    }

    /**
     * Behind a TLS proxy (a domain name) the links are https. Opened directly over plain http by an
     * IP address or localhost (e.g. http://192.168.1.10:8000 right after docker compose up), the
     * links have to stay http, or the styles and forms would point to an https that is not there.
     */
    private function openedDirectly(): bool
    {
        if ($this->app->runningInConsole() || request()->isSecure() || request()->header('X-Forwarded-Proto') === 'https') {
            return false;
        }

        $host = request()->getHost();

        return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }
}
