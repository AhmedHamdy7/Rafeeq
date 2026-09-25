<?php

namespace App\Domains\Booking\Models;

use App\Domains\Booking\Enums\PickupPointRequestStatus;
use App\Domains\Geo\Models\Place;
use App\Domains\Group\Models\GroupMember;
use App\Domains\Identity\Models\User;
use App\Domains\Shared\Casts\SpatialPoint;
use Database\Factories\PickupPointRequestFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A passenger proposing a different place to be picked up.
 *
 * 🔴 `added_minutes` and `added_km` are deliberately NOT fillable, and the reason
 * is the same one the migration states: they are computed by us from the driver's
 * own route, never claimed by the person asking. A requester who could set them
 * would be deciding how far out of their way the driver is going — and the
 * driver's `max_detour_minutes` is checked against exactly these two numbers.
 *
 * `status` and `alternative_place_id` are the DRIVER's answer, so they are not
 * fillable either: an approved request must not be creatable by asking for one.
 */
#[Fillable([
    'seat_request_id', 'group_member_id', 'requested_by_user_id', 'proposed_point',
    'proposed_label', 'effective_from',
])]
class PickupPointRequest extends Model
{
    /** @use HasFactory<PickupPointRequestFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'proposed_point' => SpatialPoint::class,
            'added_minutes' => 'decimal:1',
            'added_km' => 'decimal:2',
            'status' => PickupPointRequestStatus::class,
        ];
    }

    public function seatRequest(): BelongsTo
    {
        return $this->belongsTo(SeatRequest::class);
    }

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function alternativePlace(): BelongsTo
    {
        return $this->belongsTo(Place::class, 'alternative_place_id');
    }
}
