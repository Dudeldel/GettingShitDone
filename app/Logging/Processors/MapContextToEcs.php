<?php

namespace App\Logging\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Maps flat application context keys onto ECS field names (placed in `extra`, which the
 * EcsFormatter lifts to the top level) and removes the source keys to avoid duplication.
 * Single-user adapted: only request_id now; user_id is mapped if present (set once auth lands).
 */
class MapContextToEcs implements ProcessorInterface
{
    /** @var array<string, string> flat key => ECS field */
    private const MAP = [
        'request_id' => 'http.request.id',
        'user_id' => 'user.id',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $record->context;
        $extra = $record->extra;

        foreach (self::MAP as $flat => $ecs) {
            if (array_key_exists($flat, $context)) {
                $extra[$ecs] = $context[$flat];
                unset($context[$flat]);
            }
        }

        return $record->with(context: $context, extra: $extra);
    }
}
