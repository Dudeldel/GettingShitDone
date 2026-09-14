<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The submitted answers do not describe a complete path through the decision tree —
 * e.g. non-actionable with no destination chosen, or delegable with no "who".
 *
 * Thrown by the domain rather than defaulted away: silently picking a bucket for an
 * ill-formed answer set is precisely the "item lands somewhere arbitrary" failure FR-008
 * exists to prevent. Mapped to HTTP 422; the HTTP edge rejects most of these first, so
 * reaching this exception means the edge and the domain disagree.
 */
class InvalidClarificationException extends RuntimeException {}
