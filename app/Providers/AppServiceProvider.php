<?php

namespace App\Providers;

use App\Domain\Auth\UserRepositoryInterface;
use App\Domain\Item\ItemRepositoryInterface;
use App\Infrastructure\Auth\UserRepository;
use App\Infrastructure\Item\ItemRepository;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(ItemRepositoryInterface::class, ItemRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Document a global Sanctum bearer scheme for the OpenAPI spec. Public
        // endpoints opt out with an @unauthenticated PHPDoc tag (see HealthController).
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi): void {
                $openApi->secure(SecurityScheme::http('bearer'));
            });

        // Blanket cap on the API, keyed by user when there is one and by IP otherwise.
        // NOTE: Laravel's middleware priority list puts Authenticate ahead of
        // ThrottleRequests, so this runs AFTER auth on the protected group — it caps a
        // leaked bearer token (the asymmetry with the 5/min login limiter), it does not
        // shield the token lookup from an unauthenticated flood. Closing that would mean
        // reordering the global middleware priority, which is out of scope here.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by(
                (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
            );
        });

        // Brute-force protection for the auth endpoints, keyed by email + IP.
        RateLimiter::for('login', function (Request $request): Limit {
            $email = mb_strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.(string) $request->ip());
        });
    }
}
