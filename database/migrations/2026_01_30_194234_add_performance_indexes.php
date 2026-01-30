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
        // Epics table - heavily queried by team, status, and category
        Schema::table('epics', function (Blueprint $table) {
            $table->index('team_id');
            $table->index('status_id');
            $table->index('category_id');
        });

        // Squads table - queried by team
        Schema::table('squads', function (Blueprint $table) {
            $table->index('team_id');
        });

        // Stories table - queried by epic, squad, and status
        Schema::table('stories', function (Blueprint $table) {
            $table->index('epic_id');
            $table->index('squad_id');
            $table->index('status_id');
        });

        // Quarter plans table - queried by team and squad
        Schema::table('quarter_plans', function (Blueprint $table) {
            $table->index('team_id');
            $table->index('squad_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropIndex(['status_id']);
            $table->dropIndex(['category_id']);
        });

        Schema::table('squads', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
        });

        Schema::table('stories', function (Blueprint $table) {
            $table->dropIndex(['epic_id']);
            $table->dropIndex(['squad_id']);
            $table->dropIndex(['status_id']);
        });

        Schema::table('quarter_plans', function (Blueprint $table) {
            $table->dropIndex(['team_id']);
            $table->dropIndex(['squad_id']);
        });
    }
};
