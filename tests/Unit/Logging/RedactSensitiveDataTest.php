<?php

use App\Logging\Processors\RedactSensitiveData;

it('redacts sensitive keys case-insensitively and recursively, leaving others intact', function () {
    $out = (new RedactSensitiveData)(makeLogRecord([
        'Password' => 'hunter2',
        'nested' => ['Authorization' => 'Bearer abc', 'keep' => 'ok'],
        'user_id' => 7,
    ]));

    expect($out->context['Password'])->toBe('[REDACTED]')
        ->and($out->context['nested']['Authorization'])->toBe('[REDACTED]')
        ->and($out->context['nested']['keep'])->toBe('ok')
        ->and($out->context['user_id'])->toBe(7);
});
