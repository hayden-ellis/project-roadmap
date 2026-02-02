<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class EpicQuarterPlan extends Model
{
    use HasFactory, Sortable;

    protected $fillable = [
        'epic_id',
        'category_id',
        'squad_id',
        'quarter',
        'story_points',
        'sort_order',
        'global_sort_order',
    ];

    protected function casts(): array
    {
        return [
            'story_points' => 'integer',
            'sort_order' => 'integer',
            'global_sort_order' => 'integer',
        ];
    }

    /**
     * Sortable scope: order within squad + quarter + category.
     * Used by the Sortable trait for single-squad view ordering.
     */
    public function scopeSortable($query, $model): mixed
    {
        return $query->where('squad_id', $model->squad_id)
                     ->where('quarter', $model->quarter)
                     ->where('category_id', $model->category_id);
    }

    /**
     * Global sortable scope: order within squad + quarter only (ignores category).
     * Used for multi-squad view ordering.
     */
    public function scopeGlobalSortable($query, $model): mixed
    {
        return $query->where('squad_id', $model->squad_id)
                     ->where('quarter', $model->quarter);
    }

    /**
     * Move this model to a new global position within squad + quarter scope.
     * This is separate from the category-scoped move() method from Sortable trait.
     */
    public function moveGlobal(int $position): void
    {
        DB::transaction(function () use ($position) {
            $current = $this->global_sort_order;

            // If no global_sort_order yet, assign one at the end first
            if ($current === null) {
                $max = static::globalSortable($this)->max('global_sort_order') ?? -1;
                $current = $max + 1;
                $this->update(['global_sort_order' => $current]);
            }

            if ($current === $position) {
                return;
            }

            // Temporarily move out of the way
            $this->update(['global_sort_order' => -1]);

            // Shift items between current and target positions
            $block = static::globalSortable($this)->whereBetween('global_sort_order', [
                min($current, $position),
                max($current, $position),
            ]);

            $current < $position
                ? $block->decrement('global_sort_order')
                : $block->increment('global_sort_order');

            // Place at target position
            $this->update(['global_sort_order' => $position]);
        });
    }

    /**
     * Assign global_sort_order if not set (appends to end of squad+quarter scope).
     */
    public function ensureGlobalSortOrder(): void
    {
        if ($this->global_sort_order === null) {
            $max = static::globalSortable($this)->max('global_sort_order') ?? -1;
            $this->update(['global_sort_order' => $max + 1]);
        }
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }
}
