<?php

namespace App\Const;

/**
 * Domain constants for GTD items. Bound to a single source so validation, the schema and
 * any later display logic cannot drift apart.
 */
final class ItemConst
{
    /** Matches the items.title column; the Inbox list renders a title in full, so it stays short. */
    public const TITLE_MAX_LENGTH = 255;

    /** Upper bound on the optional long-form note — guards the request body without truncating a real thought. */
    public const NOTE_MAX_LENGTH = 10000;

    /** Matches the items.delegated_to column; a "who / what" note (FR-007), not a contact record. */
    public const DELEGATED_TO_MAX_LENGTH = 255;

    /**
     * The wire format for items.due_date (FR-011), used by every date_format: rule and every
     * ->format() call.
     *
     * Named here rather than on ClarifyConst — despite the example in app/CLAUDE.md — because
     * a due date is an item attribute and has nothing to do with the clarify workflow. It is
     * also the format the `date` cast and toDateString() already produce, so naming it makes
     * an existing de-facto contract explicit instead of introducing a second one.
     *
     * Deliberately NOT mirrored in the SPA, unlike ClarifyConst::TWO_MINUTE_SECONDS. This
     * value is already the wire format of an <input type="date">, which the HTML spec fixes
     * at YYYY-MM-DD regardless of how the browser displays it — so the SPA gets the same
     * string from the platform without holding a copy that could drift. Changing this
     * constant to anything else would break that alignment and would need a real mirror plus
     * a contract test, the way the two-minute threshold has one.
     */
    public const DATE_FORMAT = 'Y-m-d';

    /** Matches the items.context column; a GTD context label such as "@computer", not free prose. */
    public const CONTEXT_MAX_LENGTH = 64;

    /**
     * Upper bound on how many tags one item carries (FR-013).
     *
     * Bounded because tags arrive as a JSON array from the client and the column has no
     * per-element constraint of its own: without a cap, one request decides how much text the
     * row holds. Twenty is far past what a single GTD item is ever tagged with in practice.
     */
    public const TAGS_MAX_COUNT = 20;

    /** Upper bound on one tag. A tag is a label to filter by, not a sentence. */
    public const TAG_MAX_LENGTH = 32;
}
