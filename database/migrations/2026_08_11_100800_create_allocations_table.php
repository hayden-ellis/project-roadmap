<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('epic_id')->constrained()->cascadeOnDelete();

            // Snapped to the team's configured week start day.
            $table->date('week_start');

            // Fraction of the engineer's week on this epic. The UI writes 1.0
            // today (assigned / not assigned) and never exposes this field.
            // Two rows in one week therefore read as 200% -- surfaced as an
            // over-allocation warning rather than blocked, because that is a
            // conversation worth having. Exposing partial shares later is a UI
            // change with no migration.
            $table->decimal('share', 5, 4)->default(1.0);

            $table->timestamps();

            $table->unique(['engineer_id', 'epic_id', 'week_start'], 'allocations_unique');
            $table->index(['week_start', 'engineer_id']);
            $table->index(['epic_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allocations');
    }
};
