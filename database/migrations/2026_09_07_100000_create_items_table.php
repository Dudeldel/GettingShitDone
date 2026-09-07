<?php

use App\Const\ItemConst;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('title', ItemConst::TITLE_MAX_LENGTH);
            $table->text('note')->nullable();

            // Plain string + PHP enum cast, not a native MySQL enum: SQLite (the whole
            // test suite) would not enforce a native enum, so the guarantee would exist
            // only in production. The domain of values is held by GtdBucket + Rule::enum.
            $table->string('bucket', 32);

            // Dormant until S-06 (dates, FR-011) and S-07 (metadata, FR-013). The columns
            // ship now so the API shape stays stable; they have no write path yet.
            $table->date('due_date')->nullable();
            $table->json('tags')->nullable();
            $table->string('context', 64)->nullable();
            $table->boolean('important')->nullable();
            $table->boolean('urgent')->nullable();

            $table->timestamps();

            // Composite, not an index on bucket alone: the only query is
            // `where bucket = ? order by created_at desc, id desc`, so this serves
            // the filter and the ordering from one index instead of a filesort.
            $table->index(['bucket', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
