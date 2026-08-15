<?php

namespace App\Models;

use App\Traits\Sortable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A column on the board, owned by the team.
 *
 * An epic's status is stated, not inferred -- somebody moved it here. The two
 * flags are the only meaning the app reads into a status, because a name
 * alone cannot tell it which column means finished.
 */
class Status extends Model
{
    /** @use HasFactory<\Database\Factories\StatusFactory> */
    use HasFactory, Sortable;

    protected $fillable = [
        'team_id',
        'name',
        'color',
        'description',
        'sort_order',
        'is_default',
        'is_complete',
        'requires_reason',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_default' => 'boolean',
            'is_complete' => 'boolean',
            'requires_reason' => 'boolean',
        ];
    }

    /** Statuses reorder within their own team. */
    public function scopeSortable($query, $model): mixed
    {
        return $query->where('team_id', $model->team_id);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function epics(): HasMany
    {
        return $this->hasMany(Epic::class);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** The column an epic falls into when nobody chooses one. */
    public static function defaultFor(Team $team): ?self
    {
        return $team->statuses()->default()->first()
            ?? $team->statuses()->ordered()->first();
    }
}
