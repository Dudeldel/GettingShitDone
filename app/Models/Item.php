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
 * The Delegation columns (delegated_to, delegation_done) are likewise not fillable —
 * clarify writes them through an explicit update, so no request array can reach them.
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
 * @property bool|null $delegation_done
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
            'delegation_done' => 'boolean',
        ];
    }
}
