<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

it('returns the authenticated user', function () {
    $user = User::factory()->create(['email' => 'owner@example.com']);
    Sanctum::actingAs($user);

    $this->getJson('/api/me')
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonPath('email', 'owner@example.com')
        ->assertJsonPath('id', $user->getKey());
});

it('rejects unauthenticated access to /me', function () {
    $this->getJson('/api/me')->assertStatus(Response::HTTP_UNAUTHORIZED);
});
