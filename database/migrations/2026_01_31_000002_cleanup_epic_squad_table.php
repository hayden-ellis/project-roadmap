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
     * Cleanup the epic_squad table after migrating planning data to epic_quarter_plans.
     * This removes planning-related columns and consolidates duplicate records.
     */
    public function up(): void
    {
        // 1. First, consolidate duplicate records (keep one record per epic+squad)
        // For duplicates, keep the one with the earliest start_date (or first created)
        DB::statement("
            DELETE FROM epic_squad
            WHERE id NOT IN (
                SELECT id FROM (
                    SELECT MIN(id) as id
                    FROM epic_squad
                    GROUP BY epic_id, squad_id
                ) as keep_rows
            )
        ");

        // 2. Drop indexes first (SQLite requires this before dropping columns)
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
            $table->dropUnique(['epic_id', 'squad_id', 'planned_quarter']);
        });

        // 3. Remove planning columns that are now in epic_quarter_plans
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropColumn(['story_points', 'planned_quarter', 'sort_order']);
        });

        // 4. Add new unique constraint for epic+squad (one assignment per squad)
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->unique(['epic_id', 'squad_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            // Drop new unique constraint
            $table->dropUnique(['epic_id', 'squad_id']);

            // Re-add planning columns
            $table->integer('story_points')->nullable()->after('end_date');
            $table->string('planned_quarter')->nullable()->after('story_points');
            $table->integer('sort_order')->default(0)->after('planned_quarter');

            // Re-add old unique constraint
            $table->unique(['epic_id', 'squad_id', 'planned_quarter']);
        });
    }
};
