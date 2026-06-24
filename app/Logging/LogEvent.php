<?php

namespace App\Logging;

use Illuminate\Support\Facades\Log;

/**
 * Single entry point for business-meaningful domain events. Every domain event goes
 * through here (never raw Log::info) so action naming and the ECS `event.*` envelope
 * stay consistent and filterable.
 *
 * Thin seam: this generic emitter exists so the convention is in place from day one.
 * Slices add specific, well-named methods (e.g. itemClarified()) as they introduce
 * domain events; those methods delegate here.
 */
class LogEvent
{
    /**
     * Emit a structured domain event.
     *
     * @param  string  $action  dotted action name, e.g. "item.clarified.success"
     * @param  string  $category  ECS event.category, e.g. "web", "database"
     * @param  string  $outcome  "success" | "failure" | "unknown"
     * @param  array<string, mixed>  $context  additional flat context to attach
     * @param  string  $level  PSR log level (default "info")
     */
    public static function emit(
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
