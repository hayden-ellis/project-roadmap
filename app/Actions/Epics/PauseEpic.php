<?php

namespace App\Actions\Epics;

use App\Models\Allocation;
use App\Models\Epic;
use App\Models\EpicPause;
use App\Models\Status;
use App\Services\CapacityService;
use Illuminate\Support\Facades\DB;

/**
 * Stop work on an epic and say why.
 *
 * Pausing is a status: the board's flyout and the MCP server both land here
 * when an epic arrives in a column that asks for a reason, so a pause means
 * the same thing however it was recorded. Upcoming bookings are cleared
 * because they were a plan, not a record; past weeks stay, because that time
 * was actually spent and deleting them would rewrite history.
 */
final class PauseEpic
{
    public function handle(Epic $epic, string $reason, ?int $supersededById = null, ?Status $status = null): EpicPause
    {
        $team = $epic->team;
        $capacity = CapacityService::for($team);
        $week = $capacity->currentWeek();
        $wasStaffed = $capacity->isStaffedInWeek($epic);

        // The column that asks for a reason, unless the caller already chose one.
        $status ??= $team->statuses()->where('requires_reason', true)->ordered()->first();

        return DB::transaction(function () use ($epic, $reason, $supersededById, $status, $week, $capacity, $wasStaffed) {
            Allocation::where('epic_id', $epic->id)
                ->where('week_start', '>=', $week->format('Y-m-d'))
                ->delete();

            $pause = EpicPause::create([
                'epic_id' => $epic->id,
                // Stopping it now pauses it now; something already quiet keeps
                // the date it actually went silent.
                'paused_at' => $wasStaffed
                    ? $week
                    : $week->subWeeks(max(0, $capacity->weeksQuiet($epic) - 1)),
                'reason' => $reason,
                'superseded_by_epic_id' => $supersededById,
            ]);

            if ($status && $epic->status_id !== $status->id) {
                $epic->update(['status_id' => $status->id]);
            }

            return $pause;
        });
    }
}
