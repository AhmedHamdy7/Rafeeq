<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteDirection;
use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteLocation;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\CommuteRule;
use App\Domains\Commute\Models\CommuteSchedule;
use App\Domains\Commute\Models\CommuteWeekdaySchedule;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Shared\ValueObjects\Coordinate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The two create shortcuts on the commute menu (Feature 03 v5 §4 and §5).
 *
 * > *"`Add Return Commute` prefills reverse route but driver reviews independently;
 * > `Duplicate Commute` copies settings only. **Explain neither shortcut copies
 * > passengers/bookings/waiting list.**"*
 *
 * 🔴 **That last sentence is the whole specification, and it is a sentence about what must NOT
 * happen.** Everything in this class is a copy of configuration; the result is a DRAFT with nobody
 * in it. No booking, no seat request, no waiting list, no group, no generated day, no
 * `published_at`. The trap is that every one of those is reachable from the row being copied, so a
 * "copy the commute" written with a loop over relations would carry passengers into a journey they
 * never agreed to — and the first they would know of it is a car arriving for them.
 *
 * What is deliberately NOT carried over, beyond people:
 *
 *   - **`status`** — always a draft. A shortcut that published would put a journey on sale that
 *     nobody has reviewed.
 *   - **`corridor_id`** — computed at publish from the route, so copying it would attach a label
 *     that may not describe the new route at all.
 *   - **the stored route geometry** (`route_polyline`, distance, duration, bounding box) — cleared,
 *     exactly as {@see SaveCommuteRouteAction} clears it, because publish recomputes it and a draft
 *     being edited should not carry a provider's answer about a different journey.
 */
final readonly class DuplicateCommuteOfferAction
{
    /**
     * `Duplicate Commute` — the same journey again.
     *
     * Carries the weekly pattern as well as the route: the days, each weekday's departure, promised
     * arrival, price and capacity. That is the part a driver would most resent re-entering, and it
     * is genuinely the same journey.
     *
     * ⚠️ **The date window is the one thing that cannot be copied truthfully.** The source's window
     * has already started, and "start date cannot be in the past" is a rule the schedule enforces —
     * so a literal copy would hand her a draft she could not publish, with no indication of why. The
     * start moves to tomorrow instead and the end date is kept. A window that is entirely spent
     * carries no schedule at all: there is nothing left of it to carry, and inventing an end date
     * would be inventing the one number that decides how long she is committed for.
     */
    public function copy(DriverProfile $profile, CommuteOffer $source): CommuteOffer
    {
        return DB::transaction(function () use ($profile, $source): CommuteOffer {
            $draft = $this->draftFrom($profile, $source, $source->direction);

            $this->copyLocations($source, $draft, reversed: false);
            $this->copyRules($source, $draft);
            $this->copySchedule($source, $draft);

            return $draft->load(['locations', 'schedule', 'rules', 'weekdaySchedules']);
        });
    }

    /**
     * `Add Return Commute` — the same journey home.
     *
     * 🔴 **No schedule, and that is the point of the shortcut being a different one.** A return
     * journey leaves at a different time of day by definition; prefilling the outbound's 07:05 for
     * the way home would be a wrong value in the one field a driver is least likely to re-read,
     * because it is already filled in. v5 asks for a "reverse route prefill **only**", and this is
     * why that is the right scope rather than a thin one.
     */
    public function reverse(DriverProfile $profile, CommuteOffer $source): CommuteOffer
    {
        return DB::transaction(function () use ($profile, $source): CommuteOffer {
            $draft = $this->draftFrom($profile, $source, match ($source->direction) {
                CommuteDirection::ToWork => CommuteDirection::ToHome,
                CommuteDirection::ToHome => CommuteDirection::ToWork,
            });

            $this->copyLocations($source, $draft, reversed: true);
            $this->copyRules($source, $draft);

            return $draft->load(['locations', 'schedule', 'rules', 'weekdaySchedules']);
        });
    }

    /**
     * A new draft carrying the source's terms.
     *
     * 🔴 Guarded exactly like a commute created from scratch, because that is what this is. A
     * driver suspended since she published the original, a licence that lapsed, a vehicle replaced
     * or withdrawn — each has to stop the shortcut rather than produce a draft tied to a car nobody
     * has approved. The seat count is re-checked against that vehicle for the same reason: the
     * replacement may be smaller than the car the original was sized for.
     */
    private function draftFrom(DriverProfile $profile, CommuteOffer $source, CommuteDirection $direction): CommuteOffer
    {
        CreateCommuteOfferAction::assertDriverMayPublish($profile);

        $vehicle = CreateCommuteOfferAction::usableVehicle($profile, (string) $source->vehicle_id);

        CreateCommuteOfferAction::assertCommuteFitsVehicle($source, $vehicle);

        $draft = new CommuteOffer;

        $draft->fill([
            'driver_profile_id' => $profile->user_id,
            'vehicle_id' => $vehicle->id,
            'commute_type' => $source->commute_type->value,
            'direction' => $direction->value,
            'seats_total' => $source->seats_total,
            'max_detour_minutes' => $source->max_detour_minutes,
            'max_walk_minutes' => $source->max_walk_minutes,
            'audience' => $source->audience->value,
            'min_trust_level' => $source->min_trust_level,
            'allows_custom_pickup' => $source->allows_custom_pickup,
        ]);

        // Not fillable, for the same reason as on creation: a commute must never be able to declare
        // itself published.
        $draft->status = CommuteOfferStatus::Draft->value;

        $draft->save();

        return $draft;
    }

    /**
     * The route, forwards or backwards.
     *
     * 🔴 Reversing is not only swapping the ends. On the way to work the advertised stops are near
     * people's homes, where she PICKS THEM UP; on the way home those same places are where she SETS
     * THEM DOWN. So a reversed route flips each stop's role as well as the order of them — and the
     * order matters because the first home she collects from in the morning is the last one she
     * reaches in the evening.
     *
     * A reversal that kept the roles would advertise a journey home that collects passengers from
     * their own front doors, which is not a journey anybody wants.
     */
    private function copyLocations(CommuteOffer $source, CommuteOffer $draft, bool $reversed): void
    {
        $locations = $source->locations()->orderBy('sequence')->orderBy('id')->get();

        $ends = [
            CommuteLocationType::Origin->value => $locations->firstWhere('type', CommuteLocationType::Origin),
            CommuteLocationType::Destination->value => $locations->firstWhere('type', CommuteLocationType::Destination),
        ];

        $origin = $reversed
            ? $ends[CommuteLocationType::Destination->value]
            : $ends[CommuteLocationType::Origin->value];

        $destination = $reversed
            ? $ends[CommuteLocationType::Origin->value]
            : $ends[CommuteLocationType::Destination->value];

        if ($origin !== null) {
            $this->store($draft, $origin, CommuteLocationType::Origin, 0);
        }

        $middle = $locations
            ->whereIn('type', [CommuteLocationType::Pickup, CommuteLocationType::Dropoff])
            ->values();

        if ($reversed) {
            $middle = $middle->reverse()->values();
        }

        $sequence = 1;

        foreach ($middle as $stop) {
            $type = $stop->type;

            if ($reversed) {
                $type = $type === CommuteLocationType::Pickup
                    ? CommuteLocationType::Dropoff
                    : CommuteLocationType::Pickup;
            }

            $this->store($draft, $stop, $type, $sequence++);
        }

        if ($destination !== null) {
            // 999, matching `SaveCommuteRouteAction`: the gap is what lets a stop be inserted later
            // without renumbering everything after it.
            $this->store($draft, $destination, CommuteLocationType::Destination, 999);
        }
    }

    private function store(CommuteOffer $draft, CommuteLocation $from, CommuteLocationType $type, int $sequence): void
    {
        $location = new CommuteLocation;

        $location->fill([
            'commute_offer_id' => $draft->id,
            'place_id' => $from->place_id,
            'type' => $type->value,
            'point' => new Coordinate((float) $from->lat, (float) $from->lng),
            'lat' => $from->lat,
            'lng' => $from->lng,
            'address' => $from->address,
            'sequence' => $sequence,
        ]);

        $location->save();
    }

    /**
     * Her preferences — no smoking, quiet, air conditioning. The same car, so the same answers.
     */
    private function copyRules(CommuteOffer $source, CommuteOffer $draft): void
    {
        foreach ($source->rules()->orderBy('id')->get() as $rule) {
            $copy = new CommuteRule;

            $copy->fill([
                'commute_offer_id' => $draft->id,
                'rule_key' => $rule->rule_key->value,
                'rule_value' => $rule->rule_value,
            ]);

            $copy->save();
        }
    }

    /**
     * The weekly pattern, with a window she can actually use.
     *
     * Written here rather than through {@see SaveCommuteScheduleAction} on purpose: that Action
     * takes the driver's input and validates it as her decision, and this is not her input. Going
     * through it would mean assembling a `perDay` payload out of rows that already exist, only to
     * have it taken apart again — and any rule it refused would surface as a validation error on a
     * request she never made.
     *
     * ⚠️ The parent's `days_mask` and `departure_time` are derived columns whose single owner is
     * that Action. They are copied as a pair with the weekday rows they describe, from a source
     * where that Action had already made them agree — so the cache stays honest, and nothing here
     * computes it a second way.
     */
    private function copySchedule(CommuteOffer $source, CommuteOffer $draft): void
    {
        $schedule = $source->schedule()->first();

        if ($schedule === null) {
            return;
        }

        $tomorrow = CarbonImmutable::tomorrow();

        $start = CarbonImmutable::parse($schedule->start_date);
        $end = CarbonImmutable::parse($schedule->end_date);

        if ($start->lessThan($tomorrow)) {
            $start = $tomorrow;
        }

        /*
         * A window with nothing left in it carries nothing. Shifting the end as well would be
         * inventing how long she is committing for, which is hers to decide and the one number a
         * passenger plans a term around.
         */
        if ($end->lessThan($start)) {
            return;
        }

        $copy = new CommuteSchedule;

        $copy->fill([
            'commute_offer_id' => $draft->id,
            'days_mask' => $schedule->days_mask,
            'departure_time' => $schedule->departure_time,
            'timezone' => $schedule->timezone,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
        ]);

        $copy->save();

        foreach ($source->weekdaySchedules()->orderBy('weekday_bit')->get() as $weekday) {
            $dayCopy = new CommuteWeekdaySchedule;

            $dayCopy->commute_offer_id = $draft->id;
            $dayCopy->weekday_bit = $weekday->weekday_bit;
            $dayCopy->departure_time = $weekday->departure_time;
            $dayCopy->promised_arrival_time = $weekday->promised_arrival_time;
            $dayCopy->seats_total = $weekday->seats_total;

            $dayCopy->save();
        }
    }
}
