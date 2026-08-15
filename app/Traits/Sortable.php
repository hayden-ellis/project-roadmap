<?php

namespace App\Traits;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Lottery;

trait Sortable
{
    public static function bootSortable()
    {
        // Auto-order by sort_order on all queries
        static::addGlobalScope(fn ($query) => $query->orderBy('sort_order'));

        // Auto-assign sort_order on creation (appends to end of scope)
        static::creating(function ($model) {
            $max = static::sortable($model)->max('sort_order') ?? -1;
            $model->sort_order = $max + 1;
        });

        // Clean up sort_order when deleting
        static::deleting(fn ($model) => $model->displace());
    }

    /**
     * Move this model to a new position within its scope.
     */
    public function move($position)
    {
        // Occasionally clean up gaps (2 in 10 chance)
        Lottery::odds(1, outOf: 4)
            ->winner(fn () => $this->arrange())
            ->choose();

        DB::transaction(function () use ($position) {
            $current = $this->sort_order;
            $after = $position;

            if ($current === $after) {
                return;
            }

            $this->update(['sort_order' => -1]);

            $block = static::sortable($this)->whereBetween('sort_order', [
                min($current, $after),
                max($current, $after),
            ]);

            $current < $after
                ? $block->decrement('sort_order')
                : $block->increment('sort_order');

            $this->update(['sort_order' => $after]);
        });
    }

    /** Re-sequence all items in scope to 0, 1, 2, ... */
    public function arrange()
    {
        DB::transaction(function () {
            $sortOrder = 0;
            foreach (static::sortable($this)->get() as $model) {
                $model->sort_order = $sortOrder++;
                $model->save();
            }
        });
    }

    /** Move to high position (effectively remove from ordering) */
    public function displace()
    {
        $this->move(99999);
    }
}
