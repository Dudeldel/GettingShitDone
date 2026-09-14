<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The submitted answers do not describe a complete path through the decision tree.
 *
 * Thrown by the domain rather than defaulted away: silently picking a bucket for an
 * ill-formed answer set is precisely the "item lands somewhere arbitrary" failure FR-008
 * exists to prevent. Mapped to HTTP 422.
 *
 * The constructor is private and every message is a hardcoded string behind a named
 * factory. That is what makes it safe for bootstrap/app.php to render getMessage() to the
 * client: this exception cannot be constructed with interpolated request data, so the
 * privacy rule that shaped ItemPersistenceException holds here by construction rather than
 * by everyone remembering it.
 */
class InvalidClarificationException extends RuntimeException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function missingActionableAnswer(): self
    {
        return new self('Clarify needs an answer to "is it actionable?".');
    }

    public static function missingSingleStepAnswer(): self
    {
        return new self('Clarify needs an answer to "is it a single step?".');
    }

    public static function missingTwoMinuteAnswer(): self
    {
        return new self('Clarify needs an answer to "does it take less than two minutes?".');
    }

    public static function missingTwoMinuteOutcome(): self
    {
        return new self('The two-minute timer needs an outcome: done, or more time needed.');
    }

    public static function missingDelegableAnswer(): self
    {
        return new self('Clarify needs an answer to "can it be delegated?".');
    }

    public static function missingNonActionableDestination(): self
    {
        return new self('A non-actionable item must go to Trash, Someday/Maybe or Reference.');
    }

    public static function missingDelegationNote(): self
    {
        return new self('Delegating an item needs a note saying who you are waiting on.');
    }

    public static function quickRouteToInbox(): self
    {
        return new self('A quick-route needs a destination other than the Inbox.');
    }
}
