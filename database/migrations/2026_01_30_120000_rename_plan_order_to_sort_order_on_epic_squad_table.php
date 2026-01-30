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
            $table->renameColumn('plan_order', 'sort_order');
        });

        Schema::table('epic_squad', function (Blueprint $table) {
            $table->index('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropIndex(['sort_order']);
        });

        Schema::table('epic_squad', function (Blueprint $table) {
            $table->renameColumn('sort_order', 'plan_order');
        });
    }
};
