<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One engineer on one epic for one week.
 *
 * Points are NOT stored here. A cell is worth the engineer's capacity for
 * that week multiplied by share -- so editing someone's capacity updates
 * every derived total instead of leaving stale numbers behind.
 *
 * share is always 1.0 today (the UI is assigned / not assigned). It exists so
 * partial splits become a UI change rather than a migration.
 */
class Allocation extends Model
{
    /** @use HasFactory<\Database\Factories\AllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'engineer_id',
        'epic_id',
        'week_start',
        'share',
    ];

    protected function casts(): array
    {
        return [
            'share' => 'float',
        ];
    }

    /**
     * Stored as a bare Y-m-d string on every driver.
     *
     * Laravel's date cast writes "Y-m-d 00:00:00"; Postgres truncates that to
     * a date but SQLite keeps the time, so lookups by date string silently
     * miss while the unique index still fires. Normalising on write keeps
     * whereIn/firstOrCreate matching identically everywhere.
     */
    protected function weekStart(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => CarbonImmutable::parse($value)->startOfDay(),
            set: fn ($value) => CarbonImmutable::parse($value)->toDateString(),
        );
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function scopeInWeek($query, \DateTimeInterface|string $weekStart)
    {
        return $query->where('week_start', CarbonImmutable::parse($weekStart)->toDateString());
    }

    public function scopeBetweenWeeks($query, \DateTimeInterface|string $from, \DateTimeInterface|string $to)
    {
        return $query->whereBetween('week_start', [
            CarbonImmutable::parse($from)->toDateString(),
            CarbonImmutable::parse($to)->toDateString(),
        ]);
    }
}
