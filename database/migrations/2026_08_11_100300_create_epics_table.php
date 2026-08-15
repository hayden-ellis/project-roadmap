<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();

            // Progress is human-set and moves rarely. Whether an epic is
            // *active* is never stored -- it is derived from whether any
            // engineer is allocated to it in the current week. See
            // CapacityService::activityFor(). "Blocked" is deliberately absent:
            // a blocked epic is a paused one, recorded in epic_pauses.
            $table->string('progress')->default('not_started');

            $table->string('priority')->default('medium');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            // Recurring epics are re-planned into each new quarter automatically.
            $table->boolean('is_recurring')->default(false);

            $table->timestamps();

            $table->index(['team_id', 'progress']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epics');
    }
};
