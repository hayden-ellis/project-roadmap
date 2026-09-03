<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's chosen default squad on one team. See App\Support\DefaultSquad
 * for how it is read, and what a null squad_id means.
 */
class UserDefaultSquad extends Model
{
    protected $fillable = [
        'user_id',
        'team_id',
        'squad_id',
    ];

    public function squad(): BelongsTo
    {
        return $this->belongsTo(Squad::class);
    }
}
