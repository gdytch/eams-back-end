<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;

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
        // One bucket for all queued mail (Mailables and Notifications).
        RateLimiter::for('mail', fn () => Limit::perMinute((int) config('mail.rate_limit_per_minute'))->by('mail'));

        $this->app['events']->listen(SocialiteWasCalled::class, MicrosoftExtendSocialite::class.'@handle');
    }
}
