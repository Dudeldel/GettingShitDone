<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a GTD item cannot be written. Mapped to HTTP 500 in bootstrap/app.php.
 *
 * Carries only the driver's SQLSTATE code — never the query or its bindings. Laravel
 * interpolates bindings into a QueryException's message, so rethrowing that message (or
 * chaining it as `previous`) would put the user's captured text into the error log, where
 * RedactSensitiveData cannot reach it: that processor redacts by context key, not by
 * message content.
 */
class ItemPersistenceException extends RuntimeException
{
    public function __construct(private readonly string $sqlState)
    {
        parent::__construct('Could not persist the item (SQLSTATE '.$sqlState.').');
    }

    public function sqlState(): string
    {
        return $this->sqlState;
    }
}
