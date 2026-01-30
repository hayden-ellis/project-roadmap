<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the epic_quarter_plans table and migrates existing planning data
     * from epic_squad. This separates "squad assignment" from "quarter planning".
     */
    public function up(): void
    {
        // 1. Create new epic_quarter_plans table
        Schema::create('epic_quarter_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('squad_id')->constrained()->cascadeOnDelete();
            $table->string('quarter'); // e.g., "Q1-2026"
            $table->integer('story_points')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['epic_id', 'squad_id', 'quarter']);
            $table->index(['squad_id', 'quarter']); // For planning queries
        });

        // 2. Migrate existing planning data from epic_squad
        DB::statement("
            INSERT INTO epic_quarter_plans (epic_id, squad_id, quarter, story_points, sort_order, created_at, updated_at)
            SELECT epic_id, squad_id, planned_quarter, story_points, COALESCE(sort_order, 0), created_at, updated_at
            FROM epic_squad
            WHERE planned_quarter IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('epic_quarter_plans');
    }
};
