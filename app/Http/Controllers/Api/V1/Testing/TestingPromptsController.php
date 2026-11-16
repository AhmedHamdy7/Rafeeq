<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Events\AccountBanned;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Support\BannedRide;
use App\Domains\Rating\Support\RatingPrompts;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TestingPromptsController extends Controller
{
    #[ApiErrors(ErrorCode::BookingNotRateable, ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function ratingPrompt(Request $request, string $booking, RatingPrompts $prompts): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in([RatingPrompts::FIRST_RIDE, RatingPrompts::WEEKLY, RatingPrompts::UNUSUAL])],
            'unusualKind' => ['nullable', 'string', Rule::in(RatingPrompts::KINDS), 'required_if:reason,'.RatingPrompts::UNUSUAL],
        ]);

        $hers = Booking::query()->whereKey($booking)->where('passenger_user_id', $user->id)->with(['scheduledTrip', 'driverProfile.user'])->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if (! $prompts->isRateable($hers)) {
            throw DomainException::of(ErrorCode::BookingNotRateable, fields: [
                'bookingStatus' => [strtoupper($hers->status->value)],
            ]);
        }

        return ApiResponse::success([
            'ratingPrompt' => $prompts->force($hers, $data['reason'], $data['unusualKind'] ?? null),
        ]);
    }

    #[ApiErrors(ErrorCode::ValidationFailed)]
    public function accountBanned(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'activeTripId' => ['nullable', 'string', 'max:64'],
        ]);

        $activeTripId = array_key_exists('activeTripId', $data)
            ? $data['activeTripId']
            : BannedRide::activeTrip($user)?->id;

        $caseNumber = AccountSuspension::query()
            ->where('user_id', $user->id)
            ->latest('suspended_at')
            ->value('case_number');

        AccountBanned::dispatch($user->id, $caseNumber, $activeTripId);

        return ApiResponse::success(['sent' => true], status: 202);
    }
}
