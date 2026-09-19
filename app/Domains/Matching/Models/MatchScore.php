<?php

namespace App\Domains\Matching\Models;

use App\Domains\Commute\Models\ScheduledTrip;
use Database\Factories\MatchScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cached breakdown of the 100-point score (Bible §Part 2, ERD group ⑥) —
 * stored component-by-component so the "match details" screen can show the
 * breakdown, and so the formula can be tuned and compared over time.
 */
#[Fillable([
    'demand_signature', 'scheduled_trip_id', 'total', 'overlap_score', 'schedule_score',
    'detour_score', 'audience_score', 'comfort_score', 'price_score', 'reliability_score',
    'walk_minutes', 'detour_minutes', 'expires_at',
])]
class MatchScore extends Model
{
    /** @use HasFactory<MatchScoreFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'total' => 'integer',
            'overlap_score' => 'integer',
            'schedule_score' => 'integer',
            'detour_score' => 'integer',
            'audience_score' => 'integer',
            'comfort_score' => 'integer',
            'price_score' => 'integer',
            'reliability_score' => 'integer',
            'walk_minutes' => 'decimal:1',
            'detour_minutes' => 'decimal:1',
            'expires_at' => 'datetime',
        ];
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
