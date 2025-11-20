<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('quarter_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->integer('year');
            $table->integer('quarter'); // 1, 2, 3, or 4
            $table->integer('available_story_points');
            $table->timestamps();

            $table->unique(['team_id', 'squad_id', 'year', 'quarter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quarter_plans');
    }
};
