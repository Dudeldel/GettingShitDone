<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // FR-006: an item done inside the two-minute timer is COMPLETE, and there is no
            // Done bucket among the eight (FR-009) for it to move into. So completion is
            // state, not a destination: the item lands in Next Actions — what it already
            // was — and this column records that it is finished, keeping FR-008's
            // exactly-one-bucket invariant intact.
            //
            // A timestamp rather than a boolean: it answers "when", which a weekly review
            // (S-09) will want, and costs the same to write.
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
