<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The human half of a pause.
 *
 * Derivation knows an epic went quiet; only a person knows why, and what took
 * the capacity. superseded_by_epic_id is what makes the trade-off history
 * legible later ("paused twice this quarter, both times for Checkout").
 */
class EpicPause extends Model
{
    /** @use HasFactory<\Database\Factories\EpicPauseFactory> */
    use HasFactory;

    protected $fillable = [
        'epic_id',
        'paused_at',
        'resumed_at',
        'reason',
        'superseded_by_epic_id',
    ];

    /**
     * Both dates are normalised to a bare Y-m-d on write, matching
     * Allocation::weekStart(). Laravel's date cast writes "Y-m-d 00:00:00",
     * which Postgres truncates but SQLite keeps -- so an exact-match lookup
     * would quietly miss on one driver and not the other.
     */
    protected function pausedAt(): Attribute
    {
        return $this->dateOnly();
    }

    protected function resumedAt(): Attribute
    {
        return $this->dateOnly();
    }

    private function dateOnly(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->startOfDay(),
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value)->toDateString(),
        );
    }

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(Epic::class, 'superseded_by_epic_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereNull('resumed_at');
    }
}
