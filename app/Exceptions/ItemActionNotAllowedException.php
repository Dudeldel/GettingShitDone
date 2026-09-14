<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The item exists, but the action asked for is not legal for where it currently sits.
 *
 * Distinct from ItemNotFoundException (no such item) and from ItemNotInInboxException (the
 * clarify door is closed): this one says the request named a real item and a real verb, and
 * the two do not go together. Mapped to HTTP 422.
 *
 * Same discipline as InvalidClarificationException — a private constructor and a named
 * factory per case, every message a hardcoded string. That is what makes it safe for
 * bootstrap/app.php to render getMessage() to the client: the exception cannot be built
 * with interpolated request data, so the privacy rule holds by construction.
 */
class ItemActionNotAllowedException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function refileToInbox(): self
    {
        return new self('The Inbox is not a destination. Items arrive there; they are not filed there.');
    }

    public static function refileAnUnclarifiedItem(): self
    {
        return new self('That item is still in the Inbox. Clarify it rather than re-filing it.');
    }

    public static function completeOutsideActionBuckets(): self
    {
        return new self('Only Next Actions, Projects, Calendar and Delegation items can be marked done.');
    }
}
