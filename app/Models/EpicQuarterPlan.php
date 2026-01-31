<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected function casts(): array
    {
        return [
            'story_points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Sortable scope: order within squad + quarter + category.
     */
    public function scopeSortable($query, $model): mixed
    {
        return $query->where('squad_id', $model->squad_id)
                     ->where('quarter', $model->quarter)
                     ->where('category_id', $model->category_id);
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
