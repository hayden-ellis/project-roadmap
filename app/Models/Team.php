<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<\Database\Factories\TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
        'week_starts_on',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'week_starts_on' => 'integer',
        ];
    }

    public function squads(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Squad::class);
    }

    public function epics(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Epic::class);
    }

    public function engineers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Engineer::class);
    }

    public function categories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function statuses(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Status::class);
    }

    public function capacity(): \App\Services\CapacityService
    {
        return \App\Services\CapacityService::for($this);
    }

    public function weekCalendar(): \App\Support\WeekCalendar
    {
        return \App\Support\WeekCalendar::forTeam($this);
    }
}
