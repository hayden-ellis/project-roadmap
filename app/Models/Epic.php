<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Epic extends Model
{
    /** @use HasFactory<\Database\Factories\EpicFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'status_id',
        'priority',
        'title',
        'description',
        'start_date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function status(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Status::class);
    }

    public function squads(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Squad::class, 'epic_squad')
            ->withPivot('start_date', 'end_date', 'story_points', 'planned_quarter')
            ->withTimestamps();
    }

    public function overlapsQuarter(string $quarter): bool
    {
        if (! $this->start_date || ! $this->end_date) {
            return false;
        }

        $quarterDates = \App\Models\QuarterPlan::getQuarterDates($quarter);

        return $this->start_date <= $quarterDates['end']
            && $this->end_date >= $quarterDates['start'];
    }

    public function stories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Story::class);
    }
}
