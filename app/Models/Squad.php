<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A grouping of engineers and a roadmap swimlane.
 *
 * A squad no longer carries a capacity number of its own -- it is the sum of
 * its engineers' capacity, so there is exactly one place to edit it. See
 * CapacityService::squadQuarterCapacity().
 */
class Squad extends Model
{
    /** @use HasFactory<\Database\Factories\SquadFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'color',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function engineers(): HasMany
    {
        return $this->hasMany(Engineer::class);
    }

    public function quarterPlans(): HasMany
    {
        return $this->hasMany(EpicQuarterPlan::class);
    }

    public function epics()
    {
        return $this->hasManyThrough(
            Epic::class,
            EpicQuarterPlan::class,
            'squad_id',
            'id',
            'id',
            'epic_id',
        )->distinct();
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
