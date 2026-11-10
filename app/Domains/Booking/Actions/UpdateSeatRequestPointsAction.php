<?php

namespace App\Domains\Booking\Actions;

use App\Domains\Booking\Enums\MeetingPreference;
use App\Domains\Booking\Enums\SeatRequestStatus;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\RequestedPoints;
use App\Domains\Geo\Contracts\GeoQueryEngine;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

final readonly class UpdateSeatRequestPointsAction
{
    public function __construct(private GeoQueryEngine $geo) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(SeatRequest $request, array $input): SeatRequest
    {
        if ($request->status !== SeatRequestStatus::Pending || $request->requested_during_ride) {
            throw DomainException::of(ErrorCode::SeatRequestNotPending);
        }

        $offer = $request->commuteOffer()->with('locations')->firstOrFail();

        $points = RequestedPoints::fromInput($this->geo, $offer, $input);

        return DB::transaction(function () use ($request, $input, $points): SeatRequest {
            $locked = SeatRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== SeatRequestStatus::Pending) {
                throw DomainException::of(ErrorCode::SeatRequestNotPending);
            }

            $locked->forceFill([
                ...$points->columns(),
                'meeting_preference' => MeetingPreference::forPickup($input['pickup'])->value,
                ...(array_key_exists('introMessage', $input) ? ['intro_message' => $input['introMessage']] : []),
            ])->save();

            return $locked;
        });
    }
}
