<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single week that deviates from the even quarter spread -- PTO, ramp-up,
 * part-time. Sparse on purpose: no row means a normal week, so the common
 * case costs nothing to store.
 */
class EngineerWeekCapacity extends Model
{
    /** @use HasFactory<\Database\Factories\EngineerWeekCapacityFactory> */
    use HasFactory;

    protected $table = 'engineer_week_capacity';

    protected $fillable = [
        'engineer_id',
        'week_start',
        'available_points',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'available_points' => 'integer',
        ];
    }

    /** Normalised to a bare Y-m-d on write — see Allocation::weekStart(). */
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
}
