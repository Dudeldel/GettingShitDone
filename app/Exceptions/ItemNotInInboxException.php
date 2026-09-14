<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Clarify was attempted on an item that has already left the Inbox.
 *
 * Distinct from "no such item" on purpose: this is a closed door rather than a missing one,
 * and a client that cannot tell the two apart will retry forever. Mapped to HTTP 409.
 *
 * The door stays closed even though re-filing now ships (FR-010): moving a bucketed item is
 * refile()'s verb, with its own guard. Clarify remains Inbox-only — relaxing this predicate
 * is what would re-open S-02's check-then-act race.
 */
class ItemNotInInboxException extends RuntimeException {}
