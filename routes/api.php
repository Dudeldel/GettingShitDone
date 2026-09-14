<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClarifyController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Middleware\LogContextMiddleware;
use Illuminate\Support\Facades\Route;

// Public liveness probe — walking-skeleton end-to-end check.
Route::get('/health', HealthController::class);

// Public auth endpoints (rate-limited against brute force).
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');

// Authenticated endpoints. LogContextMiddleware runs after auth so user_id is resolved.
Route::middleware(['auth:sanctum', 'throttle:api', LogContextMiddleware::class])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // GTD items. Capture always targets the Inbox; the list is filtered by ?bucket=.
    Route::post('/items', [ItemController::class, 'store']);
    Route::get('/items', [ItemController::class, 'index']);

    // Clarify walks the GTD decision tree (FR-003) and routes the item out of the Inbox.
    // Its own controller: a state-changing business operation, not another item verb.
    Route::post('/items/{itemId}/clarify', [ClarifyController::class, 'store'])
        ->whereNumber('itemId');
});
