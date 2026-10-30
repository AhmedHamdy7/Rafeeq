<?php

namespace App\Domains\Reliability\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\User;
use App\Domains\Rating\Models\Rating;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Enums\ReliabilityRole;
use App\Domains\Reliability\Support\ReliabilityLedger;
use Database\Factories\ReliabilityEventFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in somebody's reliability record (Feature 03 v5).
 *
 * No `$fillable`, and that is not boilerplate here. Every row is written by
 * {@see ReliabilityLedger} from an event the system witnessed, and
 * this number can end an account — v5 routes low reliability to suspension and to a permanent ban.
 * A mass-assignable `weight` or `kind` would let a request put a mark on a stranger's record.
 */
class ReliabilityEvent extends Model
{
    /** @use HasFactory<ReliabilityEventFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'role' => ReliabilityRole::class,
            'kind' => ReliabilityEventKind::class,
            'weight' => 'integer',
            'occurred_at' => 'datetime',
            'reversed_at' => 'datetime',

            /*
             * Her appeal (Feature 03 v5 §115), kept apart from the reviewer's answer above:
             * `appealed_at` is hers and `reversed_at` is somebody else's, and only one of them
             * changes what the score says.
             */
            'appealed_at' => 'datetime',
            'appeal_declined_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'reversed_by');
    }

    /**
     * Whether this event still counts against her.
     */
    public function counts(): bool
    {
        return $this->reversed_at === null;
    }

    /**
     * Events that still count.
     *
     * 🔴 Applied explicitly by every reader rather than as a global scope, for the reason
     * {@see Rating} gives about its own visibility scope: a default
     * would make every future query LOOK safe, and the one place somebody forgets would be the
     * place a reversed event quietly counted again. An appeal that was approved and then ignored is
     * worse than no appeal process.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCounting(Builder $query): Builder
    {
        return $query->whereNull('reversed_at');
    }

    /**
     * Appeals nobody has answered yet — the reviewer's queue.
     *
     * 🔴 Open means filed and answered neither way. The two outcomes are stored in different
     * columns on purpose, so "still waiting" is the absence of both rather than a status somebody
     * has to remember to set — and an appeal that fell out of the queue because a status was missed
     * is the failure this shape makes impossible.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingAppealReview(Builder $query): Builder
    {
        return $query
            ->whereNotNull('appealed_at')
            ->whereNull('reversed_at')
            ->whereNull('appeal_declined_at');
    }

    /**
     * Whether she may still file one. Once per event, which is v5's "appeal once".
     */
    public function isAppealable(): bool
    {
        return $this->appealed_at === null && $this->reversed_at === null;
    }
}
