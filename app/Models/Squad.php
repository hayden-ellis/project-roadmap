<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Squad extends Model
{
    /** @use HasFactory<\Database\Factories\SquadFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'name',
        'description',
        'color',
    ];

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function epics(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Epic::class, 'epic_squad')
            ->withPivot('start_date', 'end_date', 'story_points', 'planned_quarter')
            ->withTimestamps();
    }

    public function quarterPlans(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(QuarterPlan::class);
    }

    public function stories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Story::class);
    }
}
