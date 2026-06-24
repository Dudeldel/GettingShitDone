<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when login credentials don't match. Mapped to HTTP 401 in bootstrap/app.php
 * so the Service stays free of the HTTP framework.
 */
class InvalidCredentialsException extends RuntimeException
{
    public function __construct(string $message = 'Invalid credentials.')
    {
        parent::__construct($message);
    }
}
