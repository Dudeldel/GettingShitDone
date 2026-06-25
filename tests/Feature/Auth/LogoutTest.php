<?php

use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'password' => 'password123',
    ]);
});

it('revokes the current token on logout', function () {
    $token = $this->postJson('/api/login', [
        'email' => 'owner@example.com',
        'password' => 'password123',
    ])->json('token');

    expect($token)->toBeString()->not->toBeEmpty();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/logout')
        ->assertStatus(Response::HTTP_NO_CONTENT);

    // The token row is gone (revocation actually happened).
    $this->assertDatabaseCount('personal_access_tokens', 0);

    // Clear the in-process guard memoization so /me re-resolves like a fresh request
    // (within one test the app instance is reused, so Sanctum would otherwise serve the
    // already-resolved user). The revoked token must then fail to authenticate.
    $this->app['auth']->forgetGuards();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/me')
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});
