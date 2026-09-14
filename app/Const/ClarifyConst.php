<?php

namespace App\Const;

/**
 * Domain constants for the clarify workflow.
 *
 * TWO_MINUTE_SECONDS is stated here and mirrored once in the SPA (frontend/src/api.ts),
 * because the timer itself is client-side — nothing in this codebase consumes the value at
 * runtime. That makes the usual "single source" claim unenforceable by ordinary use, so
 * tests/Unit/Clarify/TwoMinuteThresholdContractTest.php reads the TypeScript and fails when
 * the two drift apart. Change one, and that test tells you about the other.
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
