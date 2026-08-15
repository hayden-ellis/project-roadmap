<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_week_capacity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engineer_id')->constrained()->cascadeOnDelete();

            // Always the team's configured week start day (Tuesday by default).
            $table->date('week_start');

            $table->integer('available_points');

            // Free-text so the grid can show *why* a week is short.
            $table->string('note')->nullable();

            $table->timestamps();

            // Sparse by design: a row exists ONLY where a week deviates from the
            // even quarter spread (PTO, ramp-up, part-time). No row = normal week.
            $table->unique(['engineer_id', 'week_start']);
            $table->index('week_start');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_week_capacity');
    }
};
