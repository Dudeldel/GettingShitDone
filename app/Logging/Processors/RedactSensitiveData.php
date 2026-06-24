<?php

namespace App\Logging\Processors;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Replaces the values of sensitively-named keys with [REDACTED], recursively and
 * case-insensitively, across the log record's context and extra. Defense-in-depth:
 * the structure is preserved without the secret value.
 */
class RedactSensitiveData implements ProcessorInterface
{
    /** @var list<string> */
    private const SENSITIVE = [
        'authorization', 'password', 'password_confirmation', 'secret', 'client_secret',
        'api_key', 'apikey', 'token', 'access_token', 'refresh_token', 'bearer',
        'cookie', 'set-cookie', 'x-api-key',
    ];

    private const REDACTED = '[REDACTED]';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE, true)) {
                $data[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
