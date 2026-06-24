<?php

namespace App\Providers;

use App\Domain\Auth\UserRepositoryInterface;
use App\Infrastructure\Auth\UserRepository;
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

        // Brute-force protection for the auth endpoints, keyed by email + IP.
        RateLimiter::for('login', function (Request $request): Limit {
            $email = (string) $request->input('email');

            return Limit::perMinute(5)->by($email.'|'.(string) $request->ip());
        });
    }
}
