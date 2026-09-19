<?php

namespace App\Domains\Rating\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Enums\RatingDirection;
use Database\Factories\RatingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 🔴 Double-blind (pitfall #26): `visible_at` is NULL until both parties
 * rate each other (or 7 days pass). NOT a global scope on purpose — a user
 * must always see their OWN submitted rating; only queries fetching
 * *someone else's* rating of the current user must call
 * {@see scopeVisible()} explicitly. There is no safe default here.
 */
#[Fillable(['booking_id', 'reviewer_user_id', 'reviewed_user_id', 'direction', 'stars', 'comment'])]
class Rating extends Model
{
    /** @use HasFactory<RatingFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'direction' => RatingDirection::class,
            'stars' => 'integer',
            'visible_at' => 'datetime',
            'edit_deadline_at' => 'datetime',
            'edited_at' => 'datetime',
            'moderation_status' => ModerationStatus::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    public function reviewedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_user_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(RatingTag::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }

    public function isVisible(): bool
    {
        return $this->visible_at !== null;
    }

    /**
     * Restricts a query to ratings that are safe to show to someone other
     * than the reviewer — apply explicitly, never rely on it implicitly.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNotNull('visible_at');
    }
}
