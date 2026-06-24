<?php

use App\Logging\Processors\MapContextToEcs;

it('maps flat context keys to ECS fields and drops the source keys', function () {
    $out = (new MapContextToEcs)(makeLogRecord([
        'request_id' => 'req-123',
        'user_id' => 9,
        'other' => 'keep',
    ]));

    expect($out->extra['http.request.id'])->toBe('req-123')
        ->and($out->extra['user.id'])->toBe(9)
        ->and($out->context)->not->toHaveKey('request_id')
        ->and($out->context)->not->toHaveKey('user_id')
        ->and($out->context['other'])->toBe('keep');
});

it('leaves extra untouched when no mappable keys are present', function () {
    $out = (new MapContextToEcs)(makeLogRecord(['other' => 'keep']));

    expect($out->extra)->toBe([])
        ->and($out->context['other'])->toBe('keep');
});
