<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every epic a seat in the Eisenhower matrix.
 *
 * Importance and urgency are separate axes, not a restatement of priority --
 * priority is one ladder, the matrix is two questions. Existing epics are
 * seeded from priority anyway so the page is not empty on arrival: critical
 * lands in high/urgent, high in high/not-urgent, the rest below the line.
 * From there people drag them to where they actually belong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->string('importance')->default('low')->after('priority');
            $table->string('urgency')->default('not_urgent')->after('importance');

            // Position within a quadrant. Same reasoning as board_order: not
            // the Sortable trait, whose global orderBy would outrank the
            // explicit sorting on the epics list.
            $table->integer('matrix_order')->default(0)->after('urgency');
        });

        DB::table('epics')->whereIn('priority', ['critical', 'high'])->update(['importance' => 'high']);
        DB::table('epics')->where('priority', 'critical')->update(['urgency' => 'urgent']);

        // Seed a stable order inside each quadrant from what already exists.
        $epics = DB::table('epics')->orderBy('id')->get(['id', 'importance', 'urgency']);

        foreach ($epics->groupBy(fn ($epic) => $epic->importance.'/'.$epic->urgency) as $quadrant) {
            foreach ($quadrant->values() as $order => $epic) {
                DB::table('epics')->where('id', $epic->id)->update(['matrix_order' => $order]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('epics', function (Blueprint $table) {
            $table->dropColumn(['importance', 'urgency', 'matrix_order']);
        });
    }
};
