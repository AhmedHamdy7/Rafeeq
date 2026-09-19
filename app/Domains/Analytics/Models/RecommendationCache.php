<?php

namespace App\Domains\Analytics\Models;

use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Identity\Models\User;
use Database\Factories\RecommendationCacheFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'commute_offer_id', 'score', 'reason_codes', 'expires_at'])]
class RecommendationCache extends Model
{
    /** @use HasFactory<RecommendationCacheFactory> */
    use HasFactory, HasUlids;

    // The ERD names this table singular — Eloquent would otherwise guess
    // "recommendation_caches" (see RAFEEQ_PROGRESS.md standard #21).
    protected $table = 'recommendation_cache';

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'reason_codes' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
