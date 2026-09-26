<?php

namespace App\Http\Controllers\Api\V1\Booking;

use App\Domains\Booking\Actions\RequestSeatAction;
use App\Domains\Booking\Support\PickupDetour;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Identity\Models\User;
use App\Domains\Matching\Support\HardFilters;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Booking\RequestPickupPointRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * What a proposed meeting point would cost the driver — answered before anything is
 * created.
 *
 * Screen 13 shows `DETOUR +4′` beside the point a passenger has just dropped, while they
 * are still composing the seat request. Without this they would have to submit first and
 * read the number afterwards, which is the wrong way round: the figure is what tells them
 * whether to propose that point at all, and a refusal after submitting means starting the
 * whole request again.
 *
 * 🔒 Two things this must not become:
 *
 * - **A way to map a driver's route.** It answers one point at a time with a duration, and
 *   the same eligibility rules as the search decide whether the caller may ask about this
 *   commute at all. Somebody not allowed to see a women-only commute cannot probe its
 *   geometry through here either.
 * - **A way around the driver's limit.** The reply says whether the point is acceptable,
 *   and creating the request re-measures and re-checks server-side. Nothing here is
 *   trusted later.
 *
 * Read-only, and POST rather than GET because the body carries coordinates: a lat/lng pair
 * in a query string ends up in access logs and in any proxy in between, and this one is a
 * passenger's front door.
 */
final class PickupPreviewController extends Controller
{
    #[ApiErrors(ErrorCode::PickupNotOnCommute, ErrorCode::NotFound)]
    public function show(
        RequestPickupPointRequest $request,
        string $commute,
        GeoQueryEngine $geo,
    ): JsonResponse {
        $offer = CommuteOffer::query()->whereKey($commute)->with('locations')->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $this->assertMayAsk($request->user(), $offer);

        if (! $offer->allows_custom_pickup) {
            throw DomainException::of(ErrorCode::PickupNotOnCommute);
        }

        $detour = PickupDetour::measure($geo, $offer, new Coordinate(
            (float) $request->validated('lat'),
            (float) $request->validated('lng'),
        ));

        return ApiResponse::success([
            'addedMinutes' => $detour->addedMinutes,
            'addedKm' => $detour->addedKm,

            /*
             * The two numbers screen 29 puts in front of the driver, shown to the
             * passenger too so they can see what they are asking for rather than
             * discovering it in a refusal.
             */
            'runTotalMinutes' => $detour->runTotalMinutes,
            'maxDetourMinutes' => $offer->max_detour_minutes,

            // Stated rather than left for the client to compare two numbers and get the
            // boundary case wrong.
            'withinLimit' => $detour->isWithin($offer->max_detour_minutes),
        ]);
    }

    /**
     * 🔴 The same eligibility rules as the search, for the same reason
     * {@see RequestSeatAction} applies them: this endpoint
     * takes a commute id that may have arrived any way at all.
     *
     * Without it, a man could measure distances against a women-only commute's route —
     * learning its shape without ever being able to book it.
     */
    private function assertMayAsk(User $passenger, CommuteOffer $offer): void
    {
        $allowed = HardFilters::applyEligibility(
            CommuteOffer::query()->whereKey($offer->id),
            $passenger,
        )->exists();

        if (! $allowed) {
            // 404, not 403: telling somebody they are excluded confirms both that the
            // commute exists and what it is.
            throw DomainException::of(ErrorCode::NotFound);
        }
    }
}
