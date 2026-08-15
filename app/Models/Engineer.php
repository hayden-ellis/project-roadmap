<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person the EM plans capacity for.
 *
 * Deliberately not a User: the roster has to exist before (and often without)
 * anyone accepting a login invite. user_id links the two when it is useful.
 */
class Engineer extends Model
{
    /** @use HasFactory<\Database\Factories\EngineerFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'squad_id',
        'user_id',
        'name',
        'email',
        'title',
        'default_weekly_points',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_weekly_points' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quarterCapacities(): HasMany
    {
        return $this->hasMany(EngineerQuarterCapacity::class);
    }

    public function weekCapacities(): HasMany
    {
        return $this->hasMany(EngineerWeekCapacity::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(Allocation::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * A real photo, or null to fall back to initials.
     *
     * Deliberately not Jetstream's profile_photo_url: that silently falls back
     * to ui-avatars.com, which is a network request per face and sends the
     * team's names to a third party. Only an actually uploaded photo counts.
     */
    public function avatarUrl(): ?string
    {
        return $this->user?->profile_photo_path
            ? $this->user->profile_photo_url
            : null;
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }
}
