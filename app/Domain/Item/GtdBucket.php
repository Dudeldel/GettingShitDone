<?php

namespace App\Domain\Item;

/**
 * The eight canonical GTD buckets. Every item lives in exactly one of them (FR-008).
 *
 * Persisted as the backing string in `items.bucket`. The per-case PHPDoc below becomes
 * the description of each value in the generated OpenAPI schema.
 */
enum GtdBucket: string
{
    /** Unprocessed capture — where every new item starts. */
    case Inbox = 'inbox';

    /** Single-step actionable items, ready to be done. */
    case NextActions = 'next_actions';

    /** Multi-step actionable items promoted to a project. */
    case Projects = 'projects';

    /** Items tied to a specific date or deadline. */
    case Calendar = 'calendar';

    /** Delegated items — waiting on someone else. */
    case Delegation = 'delegation';

    /** Not actionable now, but worth revisiting later. */
    case SomedayMaybe = 'someday_maybe';

    /** Not actionable — kept for information only. */
    case Reference = 'reference';

    /** Discarded. */
    case Trash = 'trash';

    /**
     * Where an unspecified bucket resolves to. Lives here rather than at the HTTP edge so
     * changing it is a domain decision, not an edit to a FormRequest.
     */
    public static function default(): self
    {
        return self::Inbox;
    }
}
