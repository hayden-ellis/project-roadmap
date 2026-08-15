<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Points epics at a status row and retires the progress column.
 *
 * Every team gets the four columns the app used to derive, in the same
 * colours, so nothing looks different the morning after. Epics land where
 * they already appeared: an open pause wins over the progress value, because
 * that is what the board was showing.
 *
 * Written with the query builder and literal values rather than the models --
 * a migration has to keep working after the enum it was born from is deleted.
 */
return new class extends Migration
{
    /** name, colour, complete, wants a reason */
    private const DEFAULTS = [
        ['Up next', '#71717A', false, false],
        ['In progress', '#10B981', false, false],
        ['Paused', '#F59E0B', false, true],
        ['Shipped', '#3B82F6', true, false],
    ];

    public function up(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('description')
                ->constrained()->nullOnDelete();

            // Position within a board column. Deliberately not the Sortable
            // trait: its global orderBy would silently outrank the explicit
            // sorting on the epics list.
            $table->integer('board_order')->default(0)->after('status_id');
        });

        $now = now();

        foreach (DB::table('teams')->pluck('id') as $teamId) {
            $ids = [];

            foreach (self::DEFAULTS as $order => [$name, $color, $complete, $reason]) {
                $ids[$name] = DB::table('statuses')->insertGetId([
                    'team_id' => $teamId,
                    'name' => $name,
                    'color' => $color,
                    'sort_order' => $order,
                    'is_default' => $name === 'Up next',
                    'is_complete' => $complete,
                    'requires_reason' => $reason,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $epics = DB::table('epics')->where('team_id', $teamId);

            (clone $epics)->where('progress', 'not_started')->update(['status_id' => $ids['Up next']]);
            (clone $epics)->where('progress', 'in_progress')->update(['status_id' => $ids['In progress']]);
            (clone $epics)->where('progress', 'done')->update(['status_id' => $ids['Shipped']]);

            // An unresolved pause is what the board was actually showing, so
            // it outranks the progress value.
            (clone $epics)
                ->where('progress', '!=', 'done')
                ->whereIn('id', fn ($q) => $q->select('epic_id')->from('epic_pauses')->whereNull('resumed_at'))
                ->update(['status_id' => $ids['Paused']]);
        }

        // Seed a stable column order from what the board already showed.
        foreach (DB::table('epics')->orderBy('status_id')->orderBy('id')->get(['id', 'status_id']) as $i => $epic) {
            DB::table('epics')->where('id', $epic->id)->update(['board_order' => $i]);
        }

        Schema::table('epics', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'progress']);
            $table->dropColumn('progress');
            $table->index(['team_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->string('progress')->default('not_started')->after('description');
        });

        DB::table('epics')
            ->whereIn('status_id', fn ($q) => $q->select('id')->from('statuses')->where('is_complete', true))
            ->update(['progress' => 'done']);

        Schema::table('epics', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'status_id']);
            $table->dropConstrainedForeignId('status_id');
            $table->dropColumn('board_order');
            $table->index(['team_id', 'progress']);
        });

        DB::table('statuses')->delete();
    }
};
