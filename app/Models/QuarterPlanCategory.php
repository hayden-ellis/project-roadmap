<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class QuarterPlanCategory extends Pivot
{
    use Sortable;

    protected $table = 'quarter_plan_categories';

    public $incrementing = true;

    protected $fillable = [
        'quarter_plan_id',
        'category_id',
        'allocated_story_points',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'allocated_story_points' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Sortable scope: order within quarter plan.
     */
    public function scopeSortable($query, $model): mixed
    {
        return $query->where('quarter_plan_id', $model->quarter_plan_id);
    }

    public function quarterPlan(): BelongsTo
    {
        return $this->belongsTo(QuarterPlan::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
