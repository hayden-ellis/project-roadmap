<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Global sort order is used for multi-squad view ordering (squad + quarter scope).
     * The existing sort_order column is used for single-squad view (squad + quarter + category scope).
     */
    public function up(): void
    {
        Schema::table('epic_quarter_plans', function (Blueprint $table) {
            $table->unsignedInteger('global_sort_order')->nullable()->after('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_quarter_plans', function (Blueprint $table) {
            $table->dropColumn('global_sort_order');
        });
    }
};
