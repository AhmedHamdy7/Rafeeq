<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Rating\Actions\RecomputeRatingStatsAction;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Enums\ReviewReportStatus;
use App\Domains\Rating\Models\ReviewReport;
use App\Domains\Reliability\Actions\AppealReliabilityEventAction;
use App\Domains\Reliability\Enums\AppealDeclineReason;
use App\Domains\Reliability\Enums\ReliabilityEventCause;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Enums\ReliabilityRole;
use App\Domains\Reliability\Models\ReliabilityAppealEvidence;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Reliability\Models\ReliabilityEventAppeal;
use App\Domains\Reliability\Support\EventFee;
use App\Domains\Reliability\Support\ReliabilityLedger;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Verification\Support\DocumentStorage;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Testing\AgeReliabilityEventRequest;
use App\Http\Requests\Testing\DecideAppealRequest;
use App\Http\Requests\Testing\DecideReviewReportRequest;
use App\Http\Requests\Testing\WriteReliabilityEventsRequest;
use App\Http\Resources\ReliabilityResource;
use App\Http\Responses\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TestingRecordsController extends Controller
{
    #[ApiErrors(ErrorCode::AppealNotPending, ErrorCode::ServiceUnavailable, ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function decideAppeal(DecideAppealRequest $request, string $appeal, AppealReliabilityEventAction $appeals): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $found = ReliabilityEventAppeal::query()
            ->whereKey($appeal)
            ->where('user_id', $user->id)
            ->with('event')
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if (! $found->isPending()) {
            throw DomainException::of(ErrorCode::AppealNotPending);
        }

        $event = $found->event ?? throw DomainException::of(ErrorCode::NotFound);
        $note = (string) ($request->validated('note') ?? 'Decided by the test run.');

        if ($request->validated('decision') === 'approved') {
            $appeals->approve($this->reviewer(), $event, $note);
        } else {
            $appeals->decline(
                $this->reviewer(),
                $event,
                $note,
                AppealDeclineReason::from((string) ($request->validated('declineReasonCode') ?? AppealDeclineReason::Other->value)),
            );
        }

        return ApiResponse::success(ReliabilityResource::event($event->refresh()));
    }

    public function resetReliability(Request $request, DocumentStorage $storage): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $events = ReliabilityEvent::query()->where('user_id', $user->id)->get();
        $paths = ReliabilityAppealEvidence::query()->whereIn('reliability_event_id', $events->modelKeys())->pluck('file_path');

        DB::transaction(function () use ($user, $events): void {
            foreach ($events as $event) {
                $this->voidFee($user, $event);
            }

            ReliabilityEvent::query()->whereKey($events->modelKeys())->delete();
        });

        foreach ($paths as $path) {
            $storage->disk()->delete($path);
        }

        return ApiResponse::success(ReliabilityResource::for($user->id));
    }

    #[ApiErrors(ErrorCode::NotFound)]
    public function ageEvent(AgeReliabilityEventRequest $request, string $event): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $hours = (int) $request->validated('hours');

        $found = ReliabilityEvent::query()->whereKey($event)->where('user_id', $user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $fee = EventFee::entryFor($found);

        DB::transaction(function () use ($found, $fee, $hours): void {
            $this->shift('reliability_events', ['occurred_at', 'created_at', 'appealed_at', 'appeal_declined_at', 'reversed_at'], fn ($q) => $q->where('id', $found->id), $hours);
            $this->shift('reliability_event_appeals', ['created_at', 'resolved_at'], fn ($q) => $q->where('reliability_event_id', $found->id), $hours);

            if ($fee !== null) {
                $this->shift('debt_entries', ['created_at'], fn ($q) => $q->where('id', $fee->id), $hours);
            }
        });

        return ApiResponse::success(ReliabilityResource::event($found->refresh()));
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function writeEvents(WriteReliabilityEventsRequest $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $kind = ReliabilityEventKind::from((string) $request->validated('kind'));
        $cause = ReliabilityEventCause::tryFrom((string) $request->validated('cause'));
        $count = (int) ($request->validated('count') ?? 1);
        $occurredAt = CarbonImmutable::now()->subMinutes((int) ($request->validated('minutesAgo') ?? 0));
        $withFee = (bool) $request->validated('withFee');

        if ($kind->role() === ReliabilityRole::Driver
            && DriverProfile::query()->whereKey($user->id)->first()?->status !== DriverProfileStatus::Approved) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['kind' => ['DRIVER_NOT_APPROVED']]);
        }

        $booking = $this->bookingFor($user, $kind, $request->validated('bookingId'));

        if ($booking !== null && $count > 1) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['count' => ['ONE_PER_BOOKING']]);
        }

        if ($withFee && ($booking === null || EventFee::feeKinds($kind) === [])) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: ['withFee' => ['NEEDS_DRIVER_BOOKING_AND_FEE_KIND']]);
        }

        $write = function () use ($user, $kind, $cause, $count, $occurredAt, $booking): array {
            $written = [];

            for ($i = 0; $i < $count; $i++) {
                $event = ReliabilityLedger::record($user->id, $kind, $booking, occurredAt: $occurredAt, cause: $cause);

                if ($event !== null) {
                    $event->forceFill(['created_at' => $occurredAt])->save();
                    $written[] = (string) $event->getKey();
                }
            }

            return $written;
        };

        $ids = DB::transaction(function () use ($write, $request, $withFee, $booking, $kind): array {
            $ids = $request->boolean('grouped') ? ReliabilityLedger::grouped($write) : $write();

            if ($withFee && $booking !== null && $ids !== []) {
                DriverFees::charge($booking, EventFee::feeKinds($kind)[0], (int) ($request->validated('feePercent') ?? 50), 'TEST');
            }

            return $ids;
        });

        $items = [];

        foreach (ReliabilityEvent::query()->whereKey($ids)->orderBy('id')->get() as $event) {
            $items[] = ReliabilityResource::event($event);
        }

        return ApiResponse::success($items, status: 201);
    }

    #[ApiErrors(ErrorCode::ReviewReportNotPending, ErrorCode::NotFound)]
    public function decideReport(DecideReviewReportRequest $request, string $report, RecomputeRatingStatsAction $stats): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $found = ReviewReport::query()->whereKey($report)->where('reporter_id', $user->id)->with('rating')->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $open = [ReviewReportStatus::Pending, ReviewReportStatus::UnderReview];

        if (! in_array($found->status, $open, true)) {
            throw DomainException::of(ErrorCode::ReviewReportNotPending);
        }

        $rating = $found->rating ?? throw DomainException::of(ErrorCode::NotFound);
        $remove = $request->validated('decision') === 'removed';
        $reviewer = $this->reviewer();

        DB::transaction(function () use ($rating, $remove, $reviewer, $stats): void {
            $rating->forceFill([
                'moderation_status' => ($remove ? ModerationStatus::Hidden : ModerationStatus::Clean)->value,
            ])->save();

            $rating->reports()
                ->whereIn('status', [ReviewReportStatus::Pending->value, ReviewReportStatus::UnderReview->value])
                ->update([
                    'status' => ($remove ? ReviewReportStatus::Upheld : ReviewReportStatus::Dismissed)->value,
                    'resolved_by' => $reviewer->getKey(),
                    'updated_at' => now(),
                ]);

            $stats->execute($rating->reviewed_user_id);
        });

        return ApiResponse::success([
            'id' => $found->id,
            'status' => strtoupper($found->refresh()->status->value),
            'reviewVisible' => ! $remove,
        ]);
    }

    #[ApiErrors(ErrorCode::VehicleIsActive, ErrorCode::VehicleInUse, ErrorCode::NotFound)]
    public function deleteVehicle(Request $request, string $vehicle, DocumentStorage $storage): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $car = Vehicle::query()->whereKey($vehicle)->where('driver_profile_id', $user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        if ($car->is_active) {
            throw DomainException::of(ErrorCode::VehicleIsActive);
        }

        $commutes = CommuteOffer::query()->where('vehicle_id', $car->id)->pluck('id');

        if ($commutes->isNotEmpty()) {
            throw DomainException::of(ErrorCode::VehicleInUse, fields: ['commuteIds' => $commutes->all()]);
        }

        $paths = VehicleDocument::query()->where('vehicle_id', $car->id)->pluck('file_path');

        DB::transaction(function () use ($car): void {
            VehicleDocument::query()->where('vehicle_id', $car->id)->delete();
            Vehicle::query()->whereKey($car->id)->delete();
        });

        foreach ($paths as $path) {
            $storage->disk()->delete($path);
        }

        return ApiResponse::success(['vehicleId' => $car->id, 'deleted' => true]);
    }

    private function bookingFor(User $user, ReliabilityEventKind $kind, mixed $bookingId): ?Booking
    {
        if (! is_string($bookingId) || $bookingId === '') {
            return null;
        }

        return Booking::query()
            ->whereKey($bookingId)
            ->where($kind->role() === ReliabilityRole::Driver ? 'driver_profile_id' : 'passenger_user_id', $user->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private function voidFee(User $user, ReliabilityEvent $event): void
    {
        $entry = EventFee::entryFor($event);

        if ($entry === null || EventFee::isReversed($entry)) {
            return;
        }

        $key = "void:{$entry->idempotency_key}";

        if (DebtEntry::query()->where('idempotency_key', $key)->exists()) {
            return;
        }

        DebtLedger::credit(
            $user,
            DebtEntryType::Credit,
            (int) $entry->amount_piastres,
            'test_reset',
            meta: ['voids' => $entry->id],
            side: DebtSide::Driver,
            idempotencyKey: $key,
        );
    }

    private function reviewer(): AdminUser
    {
        return AdminUser::query()->orderBy('created_at')->first()
            ?? throw DomainException::of(ErrorCode::ServiceUnavailable);
    }

    /**
     * @param  list<string>  $columns
     */
    private function shift(string $table, array $columns, callable $scope, int $hours): void
    {
        $sets = [];

        foreach ($columns as $column) {
            $sets[$column] = DB::raw("DATE_SUB(`{$column}`, INTERVAL {$hours} HOUR)");
        }

        $scope(DB::table($table))->update($sets);
    }
}
