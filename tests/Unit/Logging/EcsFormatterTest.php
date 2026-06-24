<?php

use App\Logging\EcsFormatter;

it('formats a record as single-line ECS JSON with mapped fields at top level', function () {
    $line = (new EcsFormatter)->format(
        makeLogRecord(context: ['other' => 'keep'], extra: ['http.request.id' => 'req-1'], message: 'hello')
    );

    expect($line)->toEndWith("\n")
        ->and(substr_count(rtrim($line), "\n"))->toBe(0); // single line

    $decoded = json_decode(rtrim($line), true);

    expect($decoded['message'])->toBe('hello')
        ->and($decoded['log.level'])->toBe('info')
        ->and($decoded['ecs.version'])->not->toBeEmpty()
        ->and($decoded['http.request.id'])->toBe('req-1')
        ->and($decoded['labels']['other'])->toBe('keep')
        ->and($decoded)->toHaveKey('@timestamp');
});
