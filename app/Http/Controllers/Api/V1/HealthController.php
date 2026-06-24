<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class HealthController extends Controller
{
    /**
     * Liveness probe.
     *
     * Proves the full path is wired: nginx -> Octane -> Laravel. Public (no auth).
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'app' => config('app.name'),
            'environment' => config('app.env'),
            'time' => now()->toIso8601String(),
        ], Response::HTTP_OK);
    }
}
