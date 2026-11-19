<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * /horizon is open in the local environment (Horizon's default). Elsewhere
     * it needs a web session for one of HORIZON_ADMIN_EMAILS; the SPA signs in
     * with Bearer tokens, so in practice it stays closed outside local until
     * ops access is decided.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            $allowed = array_filter(array_map('trim', explode(',', (string) config('indlela.horizon_admin_emails'))));

            return $user !== null && in_array(strtolower($user->email), array_map('strtolower', $allowed), true);
        });
    }
}
