<?php

use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

it('registers the first account and returns a token', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(Response::HTTP_CREATED)
        ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']])
        ->assertJsonPath('user.email', 'owner@example.com');

    expect(User::query()->count())->toBe(1);
});

it('refuses registration once an account exists', function () {
    User::factory()->create();

    $response = $this->postJson('/api/register', [
        'name' => 'Second',
        'email' => 'second@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(Response::HTTP_FORBIDDEN);
    expect(User::query()->count())->toBe(1);
});

it('validates the registration payload', function () {
    $this->postJson('/api/register', ['email' => 'not-an-email'])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});
