<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ClarifyController;
use App\Http\Controllers\Api\V1\CompleteController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\RefileController;
use App\Http\Controllers\Api\V1\TrashController;
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

    // What an item can do AFTER clarify (FR-010 + completion). Two verbs, not one generic
    // update: a PATCH accepting arbitrary fields would let a client choose where an item
    // lands, which is the hole both item payloads are shaped to close.
    Route::post('/items/{itemId}/refile', [RefileController::class, 'store'])
        ->whereNumber('itemId');
    Route::post('/items/{itemId}/complete', [CompleteController::class, 'store'])
        ->whereNumber('itemId');

    // The Trash as a resource: deleting it empties it. No route parameter, so the product's
    // only irreversible operation cannot be aimed at a single item (see S-05 plan).
    Route::delete('/trash', [TrashController::class, 'destroy']);
});
