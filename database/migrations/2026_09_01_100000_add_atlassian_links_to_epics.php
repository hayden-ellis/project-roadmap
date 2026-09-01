<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an epic point at its counterparts in Atlassian: the delivery epic in
 * Jira and the idea in Jira Product Discovery.
 *
 * These are plain pasted URLs, not a sync -- the app stores the link and
 * derives the issue key for display. Nothing is verified against Jira, so
 * there are no credentials, no API calls, and nothing to fall out of date
 * beyond the link itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->string('jira_epic_url', 2048)->nullable()->after('is_recurring');
            $table->string('jpd_idea_url', 2048)->nullable()->after('jira_epic_url');
        });
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropColumn(['jira_epic_url', 'jpd_idea_url']);
        });
    }
};
