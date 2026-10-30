<?php

namespace App\Domains\Trip\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Enums\DisputeResolution;
use App\Domains\Trip\Enums\WarningKind;
use Database\Factories\MemberWarningFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One warning a member was given, or is about to be (Feature 03 v5 §239).
 *
 * 🔒 No `$fillable`. Every column is written by the Action that decided it: a mass-assignable
 * `ordinal` or `fee_eligible` would let a request write "third warning, fee eligible" about
 * somebody, and a mass-assignable `dispute_resolution` would let the person being warned overturn
 * her own warning.
 */
class MemberWarning extends Model
{
    /** @use HasFactory<MemberWarningFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => WarningKind::class,
            'ordinal' => 'integer',
            'fee_eligible' => 'boolean',
            'occurred_on' => 'date',
            'delivered_at' => 'datetime',
            'disputed_at' => 'datetime',
            'dispute_resolution' => DisputeResolution::class,
            'dispute_resolved_at' => 'datetime',
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

    public function disputeResolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'dispute_resolved_by');
    }

    /**
     * Warnings of one kind that still stand against her.
     *
     * 🔴 An OVERTURNED warning does not count and is not deleted — it stays readable, like a
     * reversed reliability event. So the live standing is this query, while `ordinal` on each row
     * records what she was told at the time. The two answer different questions and must not be
     * made to agree: she really was told "2nd warning", and it really no longer counts.
     *
     * A warning merely disputed still counts. Raising a dispute cannot be the thing that pauses an
     * escalation, or every warning would be disputed as a matter of course.
     *
     * @param  Builder<self>  $query
     */
    public function scopeStanding(Builder $query, string $userId, WarningKind $kind): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->where('kind', $kind->value)
            ->where(fn (Builder $q) => $q
                ->whereNull('dispute_resolution')
                ->orWhere('dispute_resolution', '!=', DisputeResolution::Overturned->value));
    }

    /**
     * Recorded but not yet told — a lateness warning waiting for her journey to end.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUndelivered(Builder $query): Builder
    {
        return $query->whereNull('delivered_at');
    }
}
