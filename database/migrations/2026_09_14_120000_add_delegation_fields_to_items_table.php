<?php

use App\Const\ItemConst;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            // FR-007: Delegation is a free-text "who / what" note plus a done flag — NOT a
            // contact or user entity. Nullable because both are meaningful for exactly one
            // of the eight buckets, following the dormant-column pattern already in this
            // table (due_date, tags, context, important, urgent).
            $table->string('delegated_to', ItemConst::DELEGATED_TO_MAX_LENGTH)->nullable();
            $table->boolean('delegation_done')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['delegated_to', 'delegation_done']);
        });
    }
};
