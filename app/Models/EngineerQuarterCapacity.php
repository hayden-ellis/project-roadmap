<?php

namespace App\Models;

use App\Support\Quarter;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An engineer's planning envelope for a quarter, e.g. 150 points. */
class EngineerQuarterCapacity extends Model
{
    /** @use HasFactory<\Database\Factories\EngineerQuarterCapacityFactory> */
    use HasFactory;

    protected $table = 'engineer_quarter_capacity';

    protected $fillable = [
        'engineer_id',
        'year',
        'quarter',
        'available_points',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'available_points' => 'integer',
        ];
    }

    public function engineer(): BelongsTo
    {
        return $this->belongsTo(Engineer::class);
    }

    public function scopeForQuarter($query, Quarter $quarter)
    {
        return $query->where('year', $quarter->year)->where('quarter', $quarter->quarter);
    }

    public function toQuarter(): Quarter
    {
        return new Quarter($this->year, $this->quarter);
    }
}
