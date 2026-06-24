<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a request id to every request so all logs for one request correlate, and
 * echoes it back on the response so clients can reference it. Runs first in the `api`
 * group (prepended in bootstrap/app.php) so even auth/bootstrap failures carry an id.
 *
 * An incoming X-Request-Id is honored ONLY if it is a valid UUID — this guards against
 * log forgery via injected newlines / control characters / oversized strings.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const CONTAINER_KEY = 'current_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);

        // Bound for queue/Octane propagation; flushed between Octane requests via
        // config('octane.flush'). Shared log context is reset by Octane's FlushLogContext.
        app()->instance(self::CONTAINER_KEY, $requestId);
        Log::shareContext(['request_id' => $requestId]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = $request->headers->get(self::HEADER);

        if (is_string($incoming) && Str::isUuid($incoming)) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
