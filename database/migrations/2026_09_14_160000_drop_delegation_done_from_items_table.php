<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // FR-007 named a "done flag" for Delegation, and this column was it. It never
            // worked: clarify wrote it once as false and nothing ever changed it — no
            // endpoint, no UI, no test asserting it moves. Completion is now one concept for
            // the whole product (completed_at), which Delegation reaches through the same
            // verb as every other action bucket. Dropping it removes dead state rather than
            // leaving a second done-flag beside a real one.
            $table->dropColumn('delegation_done');
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('delegation_done')->nullable();
        });
    }
};
