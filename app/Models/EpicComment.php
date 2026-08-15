<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversation on an epic.
 *
 * The rest of the epic's record is structured facts -- status, points, crew.
 * Comments hold the part that only reads as prose: why a decision landed the
 * way it did. Threading is a single level deep: parent_id always points at a
 * root comment, so a thread is one root plus its flat list of replies.
 */
class EpicComment extends Model
{
    /** @use HasFactory<\Database\Factories\EpicCommentFactory> */
    use HasFactory;

    protected $fillable = [
        'epic_id',
        'user_id',
        'parent_id',
        'body',
    ];

    public function epic(): BelongsTo
    {
        return $this->belongsTo(Epic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->oldest('created_at');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }
}
