<?php

namespace App\Providers;

use App\Models\Role;
use App\Models\User;
use App\Services\RankTracking\DataForSeoSerpApi;
use App\Services\RankTracking\SerpApi;
use App\Services\SearchConsole\GoogleSearchConsoleApi;
use App\Services\SearchConsole\SearchConsoleApi;
use App\Services\SiteAudit\HttpPageFetcher;
use App\Services\SiteAudit\PageFetcher;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SmsSender;
use App\Services\Sms\TwilioSmsSender;
use App\Support\CurrentWorkspace;
use App\Support\PlatformWorkspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One per request (and per job/Octane request): which workspace the code acts in.
        $this->app->scoped(CurrentWorkspace::class);

        // Search Console over Google's REST API (tests use Http::fake).
        $this->app->bind(SearchConsoleApi::class, GoogleSearchConsoleApi::class);

        // Site audit pages as raw HTML (no JavaScript); a browser renderer can replace it later.
        $this->app->bind(PageFetcher::class, HttpPageFetcher::class);

        // Rank tracking over DataForSEO's SERP API (tests use Http::fake).
        $this->app->bind(SerpApi::class, DataForSeoSerpApi::class);

        $this->app->singleton(SmsSender::class, fn () => match (config('indlela.sms.driver')) {
            'twilio' => TwilioSmsSender::fromConfig(),
            default => new LogSmsSender,
        });
    }

    public function boot(): void
    {
        // Workforce's password rules: 8+ characters, letters and numbers, at
        // least one symbol. ("Not the same as your email" is the
        // PasswordNotEmail rule, which needs the email to compare against.)
        Password::defaults(fn () => Password::min(8)->letters()->numbers()->symbols());

        // Public auth endpoints, per IP. Generous enough for an office behind
        // one NAT address; the per-account lockout does the fine-grained work.
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        // Platform › Usage: Indlela staff, acting in the platform workspace, with
        // view_platform_usage there. Never anywhere else, whatever a role holds.
        Gate::define('view-platform-usage', fn (User $user) => PlatformWorkspace::isCurrent() && $user->can('view_platform_usage')
            ? Response::allow()
            : Response::deny('Platform usage is only available to Indlela staff.'));

        // Tenancy for the models without a workspace scope of their own: a
        // `{user}` is a member of the current workspace, a `{role}` one of its
        // roles. Anything else is 404, as for clients and sites (their scope).
        Route::bind('user', fn (string $id) => User::inWorkspace($this->currentWorkspaceId())->findOrFail($id));
        Route::bind('role', fn (string $id) => Role::inWorkspace($this->currentWorkspaceId())->findOrFail($id));
    }

    private function currentWorkspaceId(): int
    {
        return app(CurrentWorkspace::class)->id() ?? abort(404);
    }
}
