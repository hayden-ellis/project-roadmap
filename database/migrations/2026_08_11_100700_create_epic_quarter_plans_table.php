<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epic_quarter_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('year');
            $table->unsignedTinyInteger('quarter'); // 1-4

            // What we think it will take.
            $table->integer('planned_points')->nullable();

            // Manually maintained actuals, updated at review. Deliberately kept
            // at epic x quarter grain -- per-engineer-per-week actuals would
            // never stay current by hand.
            $table->integer('delivered_points')->nullable();

            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // An epic may run in more than one squad and more than one quarter.
            $table->unique(['epic_id', 'squad_id', 'year', 'quarter'], 'epic_quarter_plans_unique');
            $table->index(['squad_id', 'year', 'quarter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epic_quarter_plans');
    }
};
