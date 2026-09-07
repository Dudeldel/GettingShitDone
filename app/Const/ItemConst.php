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
}
