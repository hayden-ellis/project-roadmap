<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuarterPlan extends Model
{
    /** @use HasFactory<\Database\Factories\QuarterPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'squad_id',
        'year',
        'quarter',
        'available_story_points',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'available_story_points' => 'integer',
        ];
    }

    public function team(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function squad(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }

    public function getQuarterString(): string
    {
        return "Q{$this->quarter}-{$this->year}";
    }

    public function getDateRange(): array
    {
        $startMonth = ($this->quarter - 1) * 3 + 1;

        return [
            'start' => Carbon::create($this->year, $startMonth, 1)->startOfMonth(),
            'end' => Carbon::create($this->year, $startMonth, 1)->addMonths(2)->endOfMonth(),
        ];
    }

    public static function parseQuarter(string $quarter): array
    {
        // Format: "Q1-2026"
        preg_match('/Q(\d)-(\d{4})/', $quarter, $matches);

        return [
            'year' => (int) $matches[2],
            'quarter' => (int) $matches[1],
        ];
    }

    public static function getQuarterDates(string $quarter): array
    {
        $parsed = self::parseQuarter($quarter);
        $startMonth = ($parsed['quarter'] - 1) * 3 + 1;

        return [
            'start' => Carbon::create($parsed['year'], $startMonth, 1)->startOfMonth(),
            'end' => Carbon::create($parsed['year'], $startMonth, 1)->addMonths(2)->endOfMonth(),
        ];
    }
}
