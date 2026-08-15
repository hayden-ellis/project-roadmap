<?php

namespace App\Models;

use App\Support\Quarter;
use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An epic scheduled into a squad's quarter, with the estimate and the
 * manually maintained actual.
 *
 * The old dual sort_order / global_sort_order pair is gone: ordering is now
 * scoped to squad + quarter only, because category no longer subdivides a
 * quarter's capacity.
 */
class EpicQuarterPlan extends Model
{
    /** @use HasFactory<\Database\Factories\EpicQuarterPlanFactory> */
    use HasFactory, Sortable;

    protected $fillable = [
        'epic_id',
        'squad_id',
        'year',
        'quarter',
        'planned_points',
        'delivered_points',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'planned_points' => 'integer',
            'delivered_points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** Sortable scope: ordering runs within one squad's quarter. */
    public function scopeSortable($query, $model): mixed
    {
        return $query->where('squad_id', $model->squad_id)
            ->where('year', $model->year)
            ->where('quarter', $model->quarter);
    }

    public function scopeForQuarter($query, Quarter $quarter)
    {
        return $query->where('year', $quarter->year)->where('quarter', $quarter->quarter);
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }

    public function toQuarter(): Quarter
    {
        return new Quarter($this->year, $this->quarter);
    }

    /** Points still outstanding against the estimate, or null if not tracked. */
    public function remainingPoints(): ?int
    {
        if ($this->planned_points === null) {
            return null;
        }

        return max(0, $this->planned_points - ($this->delivered_points ?? 0));
    }
}
