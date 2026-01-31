<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds estimated_story_points to epic_squad table.
     * This represents the squad's total estimate for their portion of the epic,
     * independent of how it's split across quarters.
     */
    public function up(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->integer('estimated_story_points')->nullable()->after('end_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropColumn('estimated_story_points');
        });
    }
};
