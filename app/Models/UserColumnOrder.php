<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A user's own arrangement of the board's columns on one team. See
 * App\Support\ColumnOrder for how it is applied over the team order.
 */
class UserColumnOrder extends Model
{
    protected $fillable = [
        'user_id',
        'team_id',
        'status_ids',
    ];

    protected function casts(): array
    {
        return [
            'status_ids' => 'array',
        ];
    }
}
