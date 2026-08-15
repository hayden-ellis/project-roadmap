<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epic_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epic_id')->constrained()->cascadeOnDelete();

            // Deleting a user takes their words with them, the same way
            // deleting the epic does.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Single-level threading: parent_id always points at a ROOT
            // comment (enforced in the component, not the schema). Cascade
            // means deleting a root sweeps its replies -- an orphaned reply
            // has no thread to live in.
            $table->foreignId('parent_id')->nullable()
                ->constrained('epic_comments')->cascadeOnDelete();

            $table->text('body');
            $table->timestamps();

            // The thread is always read "all comments for one epic, oldest
            // first". epic_id sits on replies too, so tenancy checks never
            // need a join through the parent.
            $table->index(['epic_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epic_comments');
    }
};
