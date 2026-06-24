<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Single entry point for business-meaningful domain events. Every domain event goes
 * through here (never raw Log::info) so action naming and the ECS `event.*` envelope
 * stay consistent and filterable.
 *
 * Thin seam: `emit()` is the protected building block so the convention is in place
 * from day one without exposing a free-form public logger. Add domain events as named
 * static methods ON this class (e.g. `itemClarified()`) that delegate to `emit()` —
 * never call a generic emitter from application code.
 */
class LogEvent
{
    /**
     * Build and emit a structured domain event. Protected on purpose: callers use the
     * named methods added to this class, not a free-form emitter.
     *
     * @param  string  $action  dotted action name, e.g. "item.clarified.success"
     * @param  string  $category  ECS event.category, e.g. "web", "database"
     * @param  string  $outcome  "success" | "failure" | "unknown"
     * @param  array<string, mixed>  $context  additional flat context to attach
     * @param  string  $level  PSR log level (default "info")
     */
    protected static function emit(
        string $action,
        string $category,
        string $outcome,
        array $context = [],
        string $level = 'info',
    ): void {
        Log::log($level, $action, array_merge($context, [
            'event' => [
                'action' => $action,
                'category' => $category,
                'outcome' => $outcome,
            ],
        ]));
    }
}
