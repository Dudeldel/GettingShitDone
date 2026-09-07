<?php

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

it('caps the authenticated API at 60 requests a minute', function () {
    Sanctum::actingAs(User::factory()->create());

    // 60 allowed, the 61st rejected — proves the limiter is actually wired to the route
    // and not merely registered in the provider.
    for ($i = 0; $i < 60; $i++) {
        $this->getJson('/api/items')->assertStatus(Response::HTTP_OK);
    }

    $this->getJson('/api/items')->assertStatus(Response::HTTP_TOO_MANY_REQUESTS);
});
