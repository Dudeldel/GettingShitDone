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
     * Whether this bucket is a legal destination for filing — i.e. anything but the Inbox.
     *
     * The Inbox is where unprocessed capture lands, never somewhere an item is put on
     * purpose: routing INTO it is the absence of a clarification, and re-filing into it
     * would mean un-clarifying something. Read by both the HTTP edge and the domain, so a
     * loosened rule at one cannot smuggle a value past the other.
     */
    public function isDestination(): bool
    {
        return $this !== self::Inbox;
    }

    /**
     * Whether an item in this bucket can be marked done.
     *
     * The four buckets below are commitments — things the user took on. Trash, Someday/Maybe
     * and Reference are what FR-004 offers for items judged NOT actionable, so "done" there
     * is a sentence nobody can interpret. The Inbox is excluded for the same reason it is not
     * a destination: its items have not been decided about yet.
     */
    public function isActionBucket(): bool
    {
        return match ($this) {
            self::NextActions, self::Projects, self::Calendar, self::Delegation => true,
            self::Inbox, self::SomedayMaybe, self::Reference, self::Trash => false,
        };
    }

    /**
     * Where an unspecified bucket resolves to. Lives here rather than at the HTTP edge so
     * changing it is a domain decision, not an edit to a FormRequest.
     */
    public static function default(): self
    {
        return self::Inbox;
    }
}
