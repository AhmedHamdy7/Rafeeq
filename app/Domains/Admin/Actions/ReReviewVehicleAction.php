<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Support\SeatPricing;
use App\Domains\Driver\Actions\ReviewVehicleAction;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * RE-REVIEW A CAR (E06): change an approved car's seats, Comfort badge or AC after approval.
 *
 * Together those ARE the price (PAY-42), so the car review's own action writes them, logged as
 * a car review is. Then each of the car's commutes gets its automatic price again, so trip days
 * created from now on are priced on the new review. Days already created keep the prices they
 * froze (PAY-44): a passenger booked on Sunday's price pays Sunday's price.
 *
 * 🔒 Never fewer approved seats than a commute on the car already sells: the seats are the
 * passengers' promise, and taking one away is the driver's decision on that commute, not a
 * side effect of a review.
 */
final readonly class ReReviewVehicleAction
{
    public function __construct(private ReviewVehicleAction $review) {}

    public function execute(AdminUser $by, Vehicle $vehicle, int $approvedSeats, VehicleTier $tier, bool $hasAc, string $reason): Vehicle
    {
        if (! $by->can(AdminPermission::DriverDecide->value)) {
            throw new AuthorizationException;
        }

        if ($vehicle->verification_status !== VehicleVerificationStatus::Approved) {
            throw ValidationException::withMessages(['reReview' => __('admin/records.vehicle.rereview_not_approved')]);
        }

        $selling = (int) self::liveCommutes($vehicle)->max('seats_total');

        if ($approvedSeats < $selling) {
            throw ValidationException::withMessages(['reSeats' => __('admin/records.vehicle.rereview_seats_taken', ['count' => $selling])]);
        }

        return DB::transaction(function () use ($by, $vehicle, $approvedSeats, $tier, $hasAc, $reason): Vehicle {
            $before = ['approved_seats' => $vehicle->approved_seats, 'tier' => $vehicle->tierOrDefault()->value, 'has_ac' => (bool) $vehicle->has_ac];

            $vehicle = $this->review->execute($vehicle, $by, $approvedSeats, $tier, $hasAc);

            // Each commute's cached automatic price, as publishing writes it (P-1).
            foreach (self::liveCommutes($vehicle) as $commute) {
                // A draft has no route yet; it is priced when it is published.
                if ($commute->status === CommuteOfferStatus::Draft || (int) $commute->route_distance_meters <= 0) {
                    continue;
                }

                $quote = SeatPricing::quote((int) $commute->route_distance_meters, $vehicle);
                $commute->forceFill([
                    'seat_price_piastres' => $quote->seatPricePiastres,
                    'driver_share_piastres' => $quote->driverSharePiastres,
                ])->save();
            }

            AdminActionLog::record($by, 'vehicle.rereview', $vehicle, $before, ['approved_seats' => $approvedSeats, 'tier' => $tier->value, 'has_ac' => $hasAc], $reason);

            return $vehicle->refresh();
        });
    }

    /**
     * What each live commute on the car would charge after the review, before anything is saved.
     *
     * @return list<array{commute: CommuteOffer, from: int, to: int}>
     */
    public static function preview(Vehicle $vehicle, int $approvedSeats, VehicleTier $tier): array
    {
        $proposed = $vehicle->replicate();
        $proposed->approved_seats = $approvedSeats;
        $proposed->tier = $tier;

        return self::liveCommutes($vehicle)->reject(fn (CommuteOffer $commute) => $commute->status === CommuteOfferStatus::Draft || (int) $commute->route_distance_meters <= 0)->map(fn (CommuteOffer $commute) => [
            'commute' => $commute,
            'from' => (int) $commute->seat_price_piastres,
            'to' => SeatPricing::quote((int) $commute->route_distance_meters, $proposed)->seatPricePiastres,
        ])->values()->all();
    }

    /** @return Collection<int, CommuteOffer> */
    private static function liveCommutes(Vehicle $vehicle): Collection
    {
        return CommuteOffer::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('status', [CommuteOfferStatus::Draft->value, CommuteOfferStatus::Published->value, CommuteOfferStatus::Paused->value])
            ->with('locations')
            ->get();
    }
}
