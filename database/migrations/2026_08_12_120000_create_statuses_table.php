<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statuses a team defines for itself.
 *
 * These replace the fixed Progress enum and the derived Activity state: the
 * board columns are now whatever the team says they are, and an epic sits in
 * exactly one of them because somebody put it there.
 *
 * Two flags carry behaviour the app still has to know about, because "which
 * of your columns means finished" cannot be guessed from a name:
 *
 *   is_complete     -- work here is done, so it drops out of planning lists
 *   requires_reason -- landing here prompts for a note (this is how Paused
 *                      keeps the explanation capture that epic_pauses holds)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color')->default('#71717A');
            $table->string('description')->nullable();

            $table->integer('sort_order')->default(0)->index();

            // Where new epics land when nobody picks a column.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_complete')->default(false);
            $table->boolean('requires_reason')->default(false);

            $table->timestamps();

            $table->unique(['team_id', 'name']);
            $table->index(['team_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statuses');
    }
};
