<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('epic_quarter_plans', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('epic_id')->constrained()->nullOnDelete();
        });

        // Copy category_id from epics to epic_quarter_plans
        DB::statement('
            UPDATE epic_quarter_plans
            SET category_id = (
                SELECT category_id FROM epics WHERE epics.id = epic_quarter_plans.epic_id
            )
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epic_quarter_plans', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }
};
