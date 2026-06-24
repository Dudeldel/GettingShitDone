<?php

use Symfony\Component\HttpFoundation\Response;

it('serves the welcome page on the root route', function () {
    $this->get('/')->assertStatus(Response::HTTP_OK);
});
