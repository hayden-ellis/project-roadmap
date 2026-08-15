<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // ISO day the planning week starts on (1 = Mon ... 7 = Sun).
            // Defaults to Tuesday to match the current sprint cadence. Every
            // week_start date in the system snaps to this day, so changing it
            // re-anchors the grid without touching stored allocations.
            $table->unsignedTinyInteger('week_starts_on')->default(2);
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('week_starts_on');
        });
    }
};
