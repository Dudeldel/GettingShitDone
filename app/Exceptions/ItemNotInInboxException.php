<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Clarify was attempted on an item that has already left the Inbox.
 *
 * Distinct from "no such item" on purpose: re-filing an already-bucketed item is FR-010,
 * deliberately deferred to v2, so this is a closed door rather than a missing one. A client
 * that cannot tell the two apart will retry forever. Mapped to HTTP 409.
 */
class ItemNotInInboxException extends RuntimeException {}
