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
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->string('planned_quarter')->nullable()->after('story_points');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropColumn('planned_quarter');
        });
    }
};
