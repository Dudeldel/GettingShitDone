<?php

use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    User::factory()->create([
        'email' => 'owner@example.com',
        'password' => 'password123',
    ]);
});

it('logs in with correct credentials and returns a token', function () {
    $this->postJson('/api/login', [
        'email' => 'owner@example.com',
        'password' => 'password123',
    ])
        ->assertStatus(Response::HTTP_OK)
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
});

it('rejects a wrong password with 401', function () {
    $this->postJson('/api/login', [
        'email' => 'owner@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(Response::HTTP_UNAUTHORIZED);
});

it('throttles repeated login attempts', function () {
    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/login', [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ]);
    }

    $this->postJson('/api/login', [
        'email' => 'owner@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
});
