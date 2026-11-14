<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Booking\Enums\SeatOfferClosedReason;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\SeatOfferClosure;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\SeatOfferResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TestingTripsController extends Controller
{
    private const array CLOSE_STATUSES = [
        SeatOfferStatus::Ineligible,
        SeatOfferStatus::Closed,
        SeatOfferStatus::TakenByOther,
    ];

    private const array NOTIFICATION_TYPES = [
        NotificationType::WaitlistClearedByChange,
        NotificationType::SeatOffered,
        NotificationType::SeatsOfferedByDriver,
        NotificationType::ChangeProposalReceived,
        NotificationType::ChangeProposalRevised,
        NotificationType::ChangeProposalApplied,
        NotificationType::ChangeProposalRejected,
        NotificationType::BookingCancelled,
    ];

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::SeatOfferClosed, ErrorCode::ValidationFailed, ErrorCode::ServiceUnavailable)]
    public function closeOffer(Request $request, string $offer): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(array_map(fn (SeatOfferStatus $s): string => strtoupper($s->value), self::CLOSE_STATUSES))],
            'closedReason' => ['sometimes', 'nullable', 'string', Rule::enum(SeatOfferClosedReason::class)],
        ]);

        $target = SeatOffer::query()
            ->whereKey($offer)
            ->whereHas('seatRequest', fn ($query) => $query->where('passenger_user_id', $user->id))
            ->with('seatRequest')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if (! $target->status->isLive()) {
            throw DomainException::of(ErrorCode::SeatOfferClosed, fields: [
                'status' => [$target->status->value],
            ]);
        }

        $status = SeatOfferStatus::from(strtolower((string) $data['status']));
        $reason = isset($data['closedReason'])
            ? SeatOfferClosedReason::from($data['closedReason'])
            : match ($status) {
                SeatOfferStatus::Ineligible => SeatOfferClosedReason::CarPassed,
                SeatOfferStatus::TakenByOther => SeatOfferClosedReason::SeatTaken,
                default => SeatOfferClosedReason::DayCancelled,
            };

        SeatOfferClosure::close($target, $status, $reason);

        return ApiResponse::success(new SeatOfferResource($target->fresh()->load(SeatOfferResource::relations())));
    }

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::ValidationFailed, ErrorCode::ServiceUnavailable)]
    public function writeNotification(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_map(fn (NotificationType $t): string => $t->value, self::NOTIFICATION_TYPES))],
            'data' => ['sometimes', 'array'],
            'data.*' => ['nullable', 'string', 'max:64'],
            'params' => ['sometimes', 'array'],
            'params.*' => ['nullable', 'string', 'max:120'],
        ]);

        $payload = array_filter($data['data'] ?? [], fn ($value): bool => $value !== null && $value !== '');

        $this->assertOwnIds($user->id, $payload);

        $notification = Notifier::send(
            $user,
            NotificationType::from($data['type']),
            params: [...['name' => 'Nour', 'date' => now()->format('j/n'), 'days' => '3'], ...($data['params'] ?? [])],
            data: $payload,
        );

        return ApiResponse::success($notification === null ? null : new NotificationResource($notification), status: 201);
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function assertOwnIds(string $userId, array $payload): void
    {
        $checks = [
            'offerId' => fn (string $id): bool => SeatOffer::query()->whereKey($id)
                ->whereHas('seatRequest', fn ($q) => $q->where('passenger_user_id', $userId))->exists(),
            'seatRequestId' => fn (string $id): bool => SeatRequest::query()->whereKey($id)->where('passenger_user_id', $userId)->exists(),
            'bookingId' => fn (string $id): bool => Booking::query()->whereKey($id)
                ->where(fn ($q) => $q->where('passenger_user_id', $userId)->orWhere('driver_profile_id', $userId))->exists(),
            'changeProposalId' => fn (string $id): bool => ChangeProposal::query()->whereKey($id)->exists(),
            'commuteId' => fn (string $id): bool => CommuteOffer::query()->whereKey($id)->exists(),
            'tripId' => fn (string $id): bool => ScheduledTrip::query()->whereKey($id)->exists(),
        ];

        foreach ($payload as $key => $id) {
            if (! isset($checks[$key]) || ! $checks[$key]((string) $id)) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    "data.{$key}" => ['Not one of your own records.'],
                ]);
            }
        }
    }
}
