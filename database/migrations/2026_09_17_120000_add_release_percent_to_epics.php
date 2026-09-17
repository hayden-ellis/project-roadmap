<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much of an epic has actually reached users, as a percentage.
 *
 * A hand-maintained number, not derived from points or Jira: the team says
 * "60% released" and that is the record. Null means nobody has said yet,
 * which is different from 0%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->unsignedTinyInteger('release_percent')->nullable()->after('jpd_idea_url');
        });
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropColumn('release_percent');
        });
    }
};
