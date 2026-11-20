<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Booking\Enums\SeatOfferClosedReason;
use App\Domains\Booking\Enums\SeatOfferStatus;
use App\Domains\Booking\Models\Booking;
use App\Domains\Booking\Models\PickupPointRequest;
use App\Domains\Booking\Models\SeatOffer;
use App\Domains\Booking\Models\SeatRequest;
use App\Domains\Booking\Support\SeatOfferClosure;
use App\Domains\Commute\Models\ChangeProposal;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Identity\Models\Device;
use App\Domains\Matching\Actions\SaveCommuteDemandAction;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use App\Domains\Notification\Contracts\PushSender;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Jobs\DeliverPushNotification;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Support\NotificationPreferences;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Resources\CommuteDemandResource;
use App\Http\Resources\NotificationResource;
use App\Http\Resources\SeatOfferResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

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

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::CommuteDemandNotEditable, ErrorCode::ServiceUnavailable)]
    public function expireDemand(Request $request, string $demand): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $target = CommuteDemand::query()
            ->whereKey($demand)
            ->where('passenger_user_id', $user->id)
            ->where('status', '!=', CommuteDemandStatus::Cancelled->value)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if ($target->status !== CommuteDemandStatus::Active) {
            throw DomainException::of(ErrorCode::CommuteDemandNotEditable, fields: [
                'status' => [strtoupper($target->status->value)],
            ]);
        }

        $target->forceFill(['expires_at' => now()->subMinute()])->save();

        SaveCommuteDemandAction::expireOverdue($target->id);

        return ApiResponse::success(new CommuteDemandResource($target->fresh()->load(CommuteDemandResource::relations())));
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

        $type = NotificationType::from($data['type']);
        $startedAt = now()->startOfSecond();
        $pushTokens = $user->devices()->whereNull('revoked_at')->whereNotNull('push_token')->count();

        $notification = Notifier::send(
            $user,
            $type,
            params: [...['name' => 'Nour', 'date' => now()->format('j/n'), 'days' => '3'], ...($data['params'] ?? [])],
            data: $payload,
        );

        $pushIds = Notification::query()
            ->where('user_id', $user->id)
            ->where('channel', NotificationChannel::Push->value)
            ->where('type', $type->value)
            ->where('created_at', '>=', $startedAt)
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return ApiResponse::success(
            $notification === null ? null : new NotificationResource($notification),
            meta: [
                'inboxWritten' => $notification !== null,
                'pushAllowed' => NotificationPreferences::allows($user, $type->category(), NotificationChannel::Push),
                'pushTokens' => $pushTokens,
                'pushIds' => $pushIds,
            ],
            status: 201,
        );
    }

    public function pushLog(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $devices = $user->devices()
            ->orderByDesc('last_seen_at')
            ->get()
            ->map(fn (Device $device): array => [
                'id' => $device->id,
                'platform' => $device->platform,
                'deviceModel' => $device->device_model,
                'appVersion' => $device->app_version,
                'hasPushToken' => $device->getRawOriginal('push_token') !== null,
                'pushTokenReadable' => self::readableToken($device) !== null,
                'pushTokenEnd' => ($token = self::readableToken($device)) === null ? null : substr($token, -8),
                'lastSeenAt' => $device->last_seen_at?->toIso8601String(),
                'revokedAt' => $device->revoked_at?->toIso8601String(),
            ])
            ->all();

        $pushes = Notification::query()
            ->where('user_id', $user->id)
            ->where('channel', NotificationChannel::Push->value)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Notification $push): array => [
                'id' => $push->id,
                'type' => $push->type,
                'title' => $push->title,
                'createdAt' => $push->created_at?->toIso8601String(),
                'sentAt' => $push->sent_at?->toIso8601String(),
                'failedReason' => $push->failed_reason,
                'state' => match (true) {
                    $push->sent_at !== null => 'sent',
                    $push->failed_reason !== null => 'failed',
                    default => 'queued',
                },
            ])
            ->all();

        try {
            $providerConfigured = app(PushSender::class)->delivers();
            $providerError = null;
        } catch (Throwable $e) {
            $providerConfigured = false;
            $providerError = class_basename($e);
        }

        return ApiResponse::success([
            'pushProviderConfigured' => $providerConfigured,
            'pushProviderError' => $providerError,
            'devices' => $devices,
            'pushes' => $pushes,
        ]);
    }

    #[ApiErrors(ErrorCode::NotFound, ErrorCode::ValidationFailed)]
    public function push(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $data = $request->validate([
            'type' => ['required', 'string', Rule::enum(NotificationType::class)],
            'data' => ['sometimes', 'array'],
            'data.*' => ['nullable', 'string', 'max:64'],
            'params' => ['sometimes', 'array'],
            'params.*' => ['nullable', 'string', 'max:120'],
        ]);

        $payload = array_filter($data['data'] ?? [], fn ($value): bool => $value !== null && $value !== '');

        $this->assertOwnIds($user->id, $payload);

        $type = NotificationType::from($data['type']);
        $locale = $user->preferred_language ?: config('app.locale');
        $params = [...['name' => 'Nour', 'date' => now()->format('j/n'), 'days' => '3'], ...($data['params'] ?? [])];

        $devices = $user->devices()->whereNull('revoked_at')->whereNotNull('push_token')->get(['id']);

        foreach ($devices as $device) {
            $push = Notification::query()->create([
                'user_id' => $user->id,
                'type' => $type->value,
                'title' => __("notifications.{$type->value}.title", $params, $locale),
                'body' => __("notifications.{$type->value}.body", $params, $locale),
                'data' => array_map('strval', $payload),
                'channel' => NotificationChannel::Push->value,
                'category' => $type->category()->value,
            ]);

            DeliverPushNotification::dispatch($push->id, $device->id, null)->afterCommit();
        }

        return ApiResponse::success([
            'type' => $type->value,
            'sent' => $devices->isEmpty() ? 0 : 1,
            'tokens' => $devices->count(),
        ], status: 202);
    }

    private static function readableToken(Device $device): ?string
    {
        try {
            $token = $device->push_token;
        } catch (DecryptException) {
            return null;
        }

        return $token === null ? null : (string) $token;
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
            'pickupChangeId' => fn (string $id): bool => PickupPointRequest::query()->whereKey($id)->exists(),
            'requestId' => fn (string $id): bool => PickupPointRequest::query()->whereKey($id)->exists()
                || SeatRequest::query()->whereKey($id)->where('passenger_user_id', $userId)->exists(),
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
