<?php

namespace App\Actions\Epics;

use App\Models\Epic;
use App\Models\Status;
use Illuminate\Support\Facades\DB;

/**
 * File an epic in another column.
 *
 * Whatever the old pause was about, it ended when the epic moved, so any open
 * pause is closed alongside the status write. The Epic model's updated hook
 * tells the people in the conversation about the move.
 */
final class ChangeEpicStatus
{
    public function handle(Epic $epic, Status $status): void
    {
        if ($epic->status_id === $status->id) {
            return;
        }

        DB::transaction(function () use ($epic, $status) {
            $epic->update(['status_id' => $status->id]);
            $epic->pauses()->open()->update(['resumed_at' => now()]);
        });
    }
}
