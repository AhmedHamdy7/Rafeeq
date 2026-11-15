<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Actions\HandleIncidentAction;
use App\Domains\Admin\Actions\ResolveAttendanceDisputeAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\Incident;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Domains\Trip\Enums\DisputeResolution;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Testing\AgeIncidentRequest;
use App\Http\Requests\Testing\DecideAttendanceDisputeRequest;
use App\Http\Requests\Testing\DecideIncidentRequest;
use App\Http\Resources\BookingResource;
use App\Http\Resources\IncidentResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class TestingCasesController extends Controller
{
    #[ApiErrors(ErrorCode::AttendanceDisputeNotOpen, ErrorCode::ServiceUnavailable, ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function decideDispute(DecideAttendanceDisputeRequest $request, string $booking, ResolveAttendanceDisputeAction $action): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $hers = Booking::query()->whereKey($booking)->where('passenger_user_id', $user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        $action->execute(
            $hers,
            $this->reviewer(),
            DisputeResolution::from((string) $request->validated('resolution')),
            $request->validated('note'),
            'Decided by the test run.',
        );

        return ApiResponse::success(new BookingResource($hers->refresh()->load([
            'scheduledTrip',
            'scheduledTrip.commuteOffer.vehicle',
            ...BookingResource::historyRelations($user),
            ...BookingResource::pickupEtaRelations(),
        ])));
    }

    #[ApiErrors(ErrorCode::IncidentClosed, ErrorCode::IncidentTransitionNotAllowed, ErrorCode::ServiceUnavailable, ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function decideIncident(DecideIncidentRequest $request, string $incident, HandleIncidentAction $handle): JsonResponse
    {
        $found = $this->ownIncident($request->user(), $incident);
        $reviewer = $this->reviewer();
        $text = trim((string) ($request->validated('resolution') ?? '')) ?: 'Decided by the test run.';

        match ($request->validated('status')) {
            'UNDER_REVIEW' => $handle->take($found, $reviewer),
            'RESOLVED' => $handle->resolve($found, $reviewer, $text),
            'CLOSED' => $handle->close($found, $reviewer, $text),
        };

        return $this->incident($found);
    }

    #[ApiErrors(ErrorCode::ValidationFailed, ErrorCode::NotFound)]
    public function ageIncident(AgeIncidentRequest $request, string $incident): JsonResponse
    {
        $found = $this->ownIncident($request->user(), $incident);
        $hours = (int) $request->validated('hours');

        DB::table('incidents')->where('id', $found->id)->whereNotNull('resolved_at')
            ->update(['resolved_at' => DB::raw("DATE_SUB(`resolved_at`, INTERVAL {$hours} HOUR)")]);

        return $this->incident($found);
    }

    private function ownIncident(?User $caller, string $incident): Incident
    {
        $user = TestAccounts::assertAllowed($caller);

        return Incident::query()->whereKey($incident)->where('reporter_user_id', $user->id)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private function incident(Incident $incident): JsonResponse
    {
        return ApiResponse::success(new IncidentResource(
            $incident->refresh()->load('evidence', 'appeals')->loadCount('evidence')
        ));
    }

    private function reviewer(): AdminUser
    {
        return AdminUser::query()->orderBy('created_at')->first()
            ?? throw DomainException::of(ErrorCode::ServiceUnavailable);
    }
}
