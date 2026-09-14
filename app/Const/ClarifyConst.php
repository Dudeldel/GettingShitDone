<?php

namespace App\Const;

/**
 * Domain constants for the clarify workflow. Bound to a single source so the backend, the
 * SPA countdown and any test that asserts on the threshold cannot drift apart.
 */
final class ClarifyConst
{
    /**
     * The GTD "two-minute rule" threshold (FR-006): below this, do it now rather than file
     * it. Drives the in-app timer's length; mirrored once into the SPA as TWO_MINUTE_SECONDS.
     */
    public const TWO_MINUTE_SECONDS = 120;

    /**
     * Upper bound on the reported timer-loop count. The value is observability only (it is
     * logged, never stored), but it arrives from the client, so it is bounded like any other
     * input rather than trusted into a log line.
     */
    public const MAX_TWO_MINUTE_LOOPS = 1000;
}
