<?php

namespace App\Domain\Clarify;

/**
 * How a two-minute timer ended (FR-006: "records the outcome (done / loop)").
 *
 * Only the two TERMINAL outcomes are cases here. A loop is not one of them — looping is the
 * timer continuing, and the user is still inside the rule. The loop count travels beside
 * this value as a plain integer and is recorded in the domain log.
 */
enum TwoMinuteOutcome: string
{
    /** The user finished the item inside the timer. The item is complete. */
    case Done = 'done';

    /** The user gave up on doing it now; the item rejoins the tree at "can it be delegated?". */
    case Deferred = 'deferred';
}
