<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pushes the authenticated user's id into the shared log context so authenticated-request
 * logs carry `user.id` (mapped by App\Logging\Processors\MapContextToEcs). Sits INSIDE
 * the auth:sanctum group — user_id only exists after authentication. Octane resets shared
 * context per request via its built-in FlushLogContext listener.
 */
class LogContextMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        if ($userId !== null) {
            Log::shareContext(['user_id' => $userId]);
        }

        return $next($request);
    }
}
