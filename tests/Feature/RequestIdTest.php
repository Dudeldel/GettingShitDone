<?php

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Str;

it('generates and echoes an X-Request-Id when none is provided', function () {
    $response = $this->getJson('/api/health');

    $echoed = $response->headers->get(AssignRequestId::HEADER);

    expect($echoed)->not->toBeNull()
        ->and(Str::isUuid($echoed))->toBeTrue();
});

it('honors a valid incoming UUID X-Request-Id', function () {
    $uuid = (string) Str::uuid();

    $response = $this->getJson('/api/health', [AssignRequestId::HEADER => $uuid]);

    expect($response->headers->get(AssignRequestId::HEADER))->toBe($uuid);
});

it('rejects a forged non-UUID X-Request-Id and replaces it with a generated one', function () {
    $forged = "not-a-uuid\r\ninjected-log-line";

    $response = $this->getJson('/api/health', [AssignRequestId::HEADER => $forged]);

    $echoed = $response->headers->get(AssignRequestId::HEADER);

    expect($echoed)->not->toBe($forged)
        ->and(Str::isUuid($echoed))->toBeTrue();
});
