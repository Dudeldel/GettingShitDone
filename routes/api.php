<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Middleware\LogContextMiddleware;
use Illuminate\Support\Facades\Route;

// Public liveness probe — walking-skeleton end-to-end check.
Route::get('/health', HealthController::class);

// Public auth endpoints (rate-limited against brute force).
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');

// Authenticated endpoints. LogContextMiddleware runs after auth so user_id is resolved.
Route::middleware(['auth:sanctum', LogContextMiddleware::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
