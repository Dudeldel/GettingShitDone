<?php

namespace App\Logging;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\LogRecord;

/**
 * Emits single-line ECS-shaped JSON on stdout (for the production `json` log channel).
 * ECS field mapping (request_id -> http.request.id, etc.) is performed upstream by
 * MapContextToEcs, which places the dotted keys into `extra`; this formatter lifts them
 * to the top level. Remaining non-ECS context is nested under `labels`.
 *
 * @see https://www.elastic.co/guide/en/ecs/current/index.html
 */
class EcsFormatter extends NormalizerFormatter
{
    private const ECS_VERSION = '8.11.0';

    public function format(LogRecord $record): string
    {
        $base = [
            '@timestamp' => $record->datetime->format($this->dateFormat),
            'log.level' => $record->level->toPsrLogLevel(),
            'message' => $record->message,
            'ecs.version' => self::ECS_VERSION,
        ];

        /** @var array<string, mixed> $extra */
        $extra = (array) $this->normalize($record->extra);

        /** @var array<string, mixed> $context */
        $context = (array) $this->normalize($record->context);

        $data = array_merge($base, $extra);

        if ($context !== []) {
            $data['labels'] = $context;
        }

        return $this->toJson($data, true)."\n";
    }
}
