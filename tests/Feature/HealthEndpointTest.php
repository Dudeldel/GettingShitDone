<?php

use Symfony\Component\HttpFoundation\Response;

it('responds ok on the health endpoint', function () {
    $this->getJson('/api/health')
        ->assertStatus(Response::HTTP_OK)
        ->assertJson(['status' => 'ok'])
        ->assertJsonStructure(['status', 'app', 'environment', 'time']);
});
