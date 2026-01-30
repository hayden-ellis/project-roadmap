<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EpicSquad extends Pivot
{
    use Sortable;

    protected $table = 'epic_squad';

    public $incrementing = true;

    protected $fillable = [
        'epic_id',
        'squad_id',
        'start_date',
        'end_date',
        'story_points',
        'planned_quarter',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'story_points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Sortable scope: order epics within squad + quarter.
     */
    public function scopeSortable($query, $model)
    {
        return $query->where('squad_id', $model->squad_id)
                     ->where('planned_quarter', $model->planned_quarter);
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
