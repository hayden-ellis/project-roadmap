<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class EpicSquad extends Pivot
{
    protected $table = 'epic_squad';

    public $incrementing = true;

    protected $fillable = [
        'epic_id',
        'squad_id',
        'start_date',
        'end_date',
        'estimated_story_points',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
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
