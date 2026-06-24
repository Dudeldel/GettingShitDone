<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when register is attempted after the single account already exists. Mapped to
 * HTTP 403 in bootstrap/app.php so the Service stays free of the HTTP framework.
 */
class RegistrationClosedException extends RuntimeException
{
    public function __construct(string $message = 'Registration is closed.')
    {
        parent::__construct($message);
    }
}
