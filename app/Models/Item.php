<?php

namespace App\Models;

use App\Domain\Item\GtdBucket;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A captured GTD item. ORM mapping only — business logic lives in the domain layer.
 *
 * The dormant metadata columns (due_date, tags, context, important, urgent) are cast but
 * deliberately NOT fillable: S-06/S-07 add their write paths together with validation.
 * The Delegation column (delegated_to) is likewise not fillable —
 * clarify writes them through an explicit update, so no request array can reach them.
 * completed_at follows the same rule, and now has TWO write paths rather than one: clarify's
 * two-minute branch (FR-006) and the /complete verb. Both go through explicit updates in the
 * repository, which is what keeps it out of reach of any request array.
 *
 * @property int $id
 * @property string $title
 * @property string|null $note
 * @property GtdBucket $bucket
 * @property Carbon|null $due_date
 * @property array<int, string>|null $tags
 * @property string|null $context
 * @property bool|null $important
 * @property bool|null $urgent
 * @property string|null $delegated_to
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'note', 'bucket'])]
class Item extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bucket' => GtdBucket::class,
            'due_date' => 'date',
            'tags' => 'array',
            'important' => 'boolean',
            'urgent' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }
}
