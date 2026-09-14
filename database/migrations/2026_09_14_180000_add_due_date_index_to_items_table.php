<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // Serves the `due_date is not null` half of the Calendar view's predicate, and any
            // later date-range query (a "this week" view, the weekly review).
            //
            // Deliberately NOT claimed to remove the sort. The Calendar query is an OR across
            // two branches — filed-to-Calendar, or dated-and-actionable — and with a predicate
            // of that shape the optimizer will most likely still sort the result rather than
            // walk this index in order. Stated plainly because the composite index on this
            // table was justified by naming the exact query it serves, and the honest version
            // of that justification here is "the filter, not the ordering".
            $table->index('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropIndex(['due_date']);
        });
    }
};
