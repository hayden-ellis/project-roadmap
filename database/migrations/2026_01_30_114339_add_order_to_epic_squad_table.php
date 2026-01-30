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
            $table->integer('plan_order')->nullable()->after('planned_quarter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropColumn('plan_order');
        });
    }
};
