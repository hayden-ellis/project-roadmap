<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_quarter_capacity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year');
            $table->unsignedTinyInteger('quarter'); // 1-4

            // The planning envelope, e.g. "Sarah has 150 points this quarter".
            // Spread evenly across the quarter's weeks unless a row in
            // engineer_week_capacity overrides a specific week.
            $table->integer('available_points');

            $table->timestamps();

            $table->unique(['engineer_id', 'year', 'quarter']);
            $table->index(['year', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_quarter_capacity');
    }
};
