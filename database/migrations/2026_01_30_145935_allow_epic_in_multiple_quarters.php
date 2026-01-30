<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Change unique constraint from [epic_id, squad_id] to [epic_id, squad_id, planned_quarter]
     * This allows the same epic to exist in multiple quarters with independent story points.
     */
    public function up(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropUnique(['epic_id', 'squad_id']);
            $table->unique(['epic_id', 'squad_id', 'planned_quarter']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_squad', function (Blueprint $table) {
            $table->dropUnique(['epic_id', 'squad_id', 'planned_quarter']);
            $table->unique(['epic_id', 'squad_id']);
        });
    }
};
