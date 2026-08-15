<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

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
        'avatar_path',
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
     * The engineer's own uploaded photo wins; a linked user account's photo
     * is the fallback for people who manage their own.
     *
     * Deliberately not Jetstream's profile_photo_url: that silently falls back
     * to ui-avatars.com, which is a network request per face and sends the
     * team's names to a third party. Only an actually uploaded photo counts.
     */
    public function avatarUrl(): ?string
    {
        if ($this->avatar_path) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        return $this->user?->profile_photo_path
            ? $this->user->profile_photo_url
            : null;
    }

    /** Stores the new photo, replaces the path, and cleans up the old file. */
    public function updateAvatar(UploadedFile $photo): void
    {
        $previous = $this->avatar_path;

        $this->update(['avatar_path' => $photo->store('engineer-avatars', 'public')]);

        if ($previous) {
            Storage::disk('public')->delete($previous);
        }
    }

    public function deleteAvatar(): void
    {
        if (! $this->avatar_path) {
            return;
        }

        Storage::disk('public')->delete($this->avatar_path);

        $this->update(['avatar_path' => null]);
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
