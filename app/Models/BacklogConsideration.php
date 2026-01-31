<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BacklogConsideration extends Model
{
    use Sortable;

    protected $fillable = [
        'epic_id',
        'squad_id',
        'quarter',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    /**
     * Sortable scope: order within squad + quarter.
     */
    public function scopeSortable($query, $model): mixed
    {
        return $query->where('squad_id', $model->squad_id)
            ->where('quarter', $model->quarter);
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
