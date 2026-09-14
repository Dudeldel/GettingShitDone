<?php

namespace App\Logging;

use App\Domain\Clarify\TwoMinuteOutcome;
use App\Domain\Item\GtdBucket;
use Illuminate\Support\Facades\Log;

/**
 * Single entry point for business-meaningful domain events. Every domain event goes
 * through here (never raw Log::info) so action naming and the ECS `event.*` envelope
 * stay consistent and filterable.
 *
 * Thin seam: `emit()` is the protected building block so the convention is in place
 * from day one without exposing a free-form public logger. Add domain events as named
 * static methods ON this class (e.g. `itemClarified()`) that delegate to `emit()` —
 * never call a generic emitter from application code.
 */
class LogEvent
{
    /**
     * An idea was captured into a GTD bucket (FR-001).
     *
     * @param  int  $itemId  id of the freshly created item
     * @param  GtdBucket  $bucket  where it landed — always the Inbox on capture today
     */
    public static function itemCaptured(int $itemId, GtdBucket $bucket): void
    {
        self::emit('item.captured.success', 'database', 'success', [
            'item_id' => $itemId,
            'bucket' => $bucket->value,
        ]);
    }

    /**
     * An item was routed out of the Inbox by clarify (FR-008).
     *
     * @param  int  $itemId  the clarified item
     * @param  GtdBucket  $bucket  the single destination it landed in
     */
    public static function itemClarified(int $itemId, GtdBucket $bucket): void
    {
        self::emit('item.clarified.success', 'database', 'success', [
            'item_id' => $itemId,
            'bucket' => $bucket->value,
        ]);
    }

    /**
     * A two-minute timer ran during clarify and ended (FR-006: "records the outcome
     * (done / loop)").
     *
     * The loop count lives here rather than in a column on purpose: nothing in the MVP
     * reads it back, and a stored value with no reader is state that has to be kept correct
     * forever for nobody. The durable half of the record is completed_at on the item; this
     * is the observability half, and it is the only place the loops are visible at all.
     *
     * @param  int  $itemId  the item the timer ran for
     * @param  TwoMinuteOutcome  $outcome  done (the user finished it) or deferred
     * @param  int  $loops  how many times the user asked for more time before deciding
     */
    public static function twoMinuteRuleApplied(int $itemId, TwoMinuteOutcome $outcome, int $loops): void
    {
        self::emit('clarify.two_minute_rule.'.$outcome->value, 'session', 'success', [
            'item_id' => $itemId,
            'two_minute_outcome' => $outcome->value,
            'two_minute_loops' => $loops,
        ]);
    }

    /**
     * A clarification could not be applied (FR-008, guardrail "clarify never leaves an item
     * without a bucket"). Carries the intended destination and a reason, never the item's
     * text — the same privacy rule as the capture events.
     *
     * @param  int  $itemId  the item that stayed put
     * @param  GtdBucket  $bucket  where it was meant to go
     * @param  string  $reason  SQLSTATE or a short cause, never a query or its bindings
     */
    public static function itemClarifyFailed(int $itemId, GtdBucket $bucket, string $reason): void
    {
        self::emit('item.clarified.failure', 'database', 'failure', [
            'item_id' => $itemId,
            'bucket' => $bucket->value,
            'reason' => $reason,
        ], 'error');
    }

    /**
     * The Trash was emptied (FR-004's destination, completed).
     *
     * The only irreversible operation in the product, so it gets a durable trace. The count
     * travels; the items' text never does.
     *
     * @param  int  $count  how many items were permanently discarded
     */
    public static function trashEmptied(int $count): void
    {
        self::emit('trash.emptied.success', 'database', 'success', [
            'deleted_count' => $count,
        ]);
    }

    /**
     * The Trash could not be emptied.
     *
     * @param  string  $reason  SQLSTATE, never a query or its bindings
     */
    public static function trashEmptyFailed(string $reason): void
    {
        self::emit('trash.emptied.failure', 'database', 'failure', [
            'reason' => $reason,
        ], 'error');
    }

    /**
     * A capture could not be persisted (FR-001, guardrail "capture never loses an entry").
     *
     * @param  GtdBucket  $bucket  the intended destination
     * @param  string  $sqlState  driver SQLSTATE code — never the query or its bindings
     */
    public static function itemCaptureFailed(GtdBucket $bucket, string $sqlState): void
    {
        self::emit('item.captured.failure', 'database', 'failure', [
            'bucket' => $bucket->value,
            'reason' => $sqlState,
        ], 'error');
    }

    /**
     * Build and emit a structured domain event. Protected on purpose: callers use the
     * named methods added to this class, not a free-form emitter.
     *
     * @param  string  $action  dotted action name, e.g. "item.clarified.success"
     * @param  string  $category  ECS event.category, e.g. "web", "database"
     * @param  string  $outcome  "success" | "failure" | "unknown"
     * @param  array<string, mixed>  $context  additional flat context to attach
     * @param  string  $level  PSR log level (default "info")
     */
    protected static function emit(
        string $action,
        string $category,
        string $outcome,
        array $context = [],
        string $level = 'info',
    ): void {
        Log::log($level, $action, array_merge($context, [
            'event' => [
                'action' => $action,
                'category' => $category,
                'outcome' => $outcome,
            ],
        ]));
    }
}
