<?php

namespace App\Domains\Identity\Models;

use Database\Factories\UserStatFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 1:1 with users — the primary key IS the user_id foreign key, so HasUlids
 * (which generates a new id) is intentionally not used here; no
 * guarded/fillable list either, since this table is only ever written by
 * system jobs recomputing stats, never from user input.
 */
class UserStat extends Model
{
    /** @use HasFactory<UserStatFactory> */
    use HasFactory;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'completed_trips_as_passenger' => 'integer',
            'completed_trips_as_driver' => 'integer',
            'avg_rating_as_passenger' => 'decimal:2',
            'avg_rating_as_driver' => 'decimal:2',
            'on_time_rate' => 'decimal:2',
            'cancellation_rate' => 'decimal:2',
            'no_show_count' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
