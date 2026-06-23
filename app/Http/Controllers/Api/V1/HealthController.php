<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class HealthController extends Controller
{
    /**
     * Liveness probe for the walking-skeleton deploy.
     *
     * Proves the full path is wired: nginx -> Octane -> Laravel. Public (no auth).
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
