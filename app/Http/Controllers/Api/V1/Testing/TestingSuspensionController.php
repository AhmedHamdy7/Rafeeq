<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Admin\Actions\ResolveSuspensionAppealAction;
use App\Domains\Admin\Actions\SuspendMemberAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Enums\AccountStatus;
use App\Domains\Identity\Enums\SuspensionReason;
use App\Domains\Identity\Models\AccountSuspension;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Identity\Models\User;
use App\Domains\Identity\Support\LaunchRouter;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Testing\AgeSuspensionRequest;
use App\Http\Requests\Testing\BanAccountRequest;
use App\Http\Requests\Testing\DecideSuspensionAppealRequest;
use App\Http\Resources\SuspensionSummary;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class TestingSuspensionController extends Controller
{
    #[ApiErrors(ErrorCode::SuspensionAppealNotOpen, ErrorCode::MemberNotSuspended, ErrorCode::ServiceUnavailable, ErrorCode::ValidationFailed)]
    public function decideAppeal(DecideSuspensionAppealRequest $request, ResolveSuspensionAppealAction $action): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $suspension = $this->holdOf($user);

        $appeal = SuspensionAppeal::query()
            ->where('account_suspension_id', $suspension->id)
            ->whereNull('resolution')
            ->latest('attempt')
            ->first()
            ?? throw DomainException::of(ErrorCode::SuspensionAppealNotOpen);

        $action->execute(
            $appeal,
            $this->reviewer(),
            $request->validated('decision') === 'overturned',
            trim((string) ($request->validated('note') ?? '')) ?: 'Decided by the test run.',
        );

        return $this->standing($user);
    }

    #[ApiErrors(ErrorCode::MemberNotSuspended, ErrorCode::ValidationFailed)]
    public function age(AgeSuspensionRequest $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());
        $suspension = $this->holdOf($user);
        $hours = (int) $request->validated('hours');

        DB::transaction(function () use ($suspension, $hours): void {
            $back = fn (string $column) => DB::raw("DATE_SUB(`{$column}`, INTERVAL {$hours} HOUR)");

            DB::table('account_suspensions')->where('id', $suspension->id)->update([
                'suspended_at' => $back('suspended_at'),
                'review_due_at' => $back('review_due_at'),
            ]);

            DB::table('suspension_appeals')->where('account_suspension_id', $suspension->id)->update([
                'created_at' => $back('created_at'),
                'resolved_at' => DB::raw("CASE WHEN `resolved_at` IS NULL THEN NULL ELSE DATE_SUB(`resolved_at`, INTERVAL {$hours} HOUR) END"),
            ]);
        });

        return $this->standing($user);
    }

    #[ApiErrors(ErrorCode::MemberNotActive, ErrorCode::ServiceUnavailable, ErrorCode::ValidationFailed)]
    public function ban(BanAccountRequest $request, SuspendMemberAction $members): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $members->suspend(
            $user,
            $this->reviewer(),
            SuspensionReason::PolicyBreach,
            trim((string) ($request->validated('reason') ?? '')) ?: 'Banned by the test run.',
            permanent: true,
        );

        return $this->standing($user);
    }

    private function holdOf(User $user): AccountSuspension
    {
        if ($user->account_status !== AccountStatus::Suspended) {
            throw DomainException::of(ErrorCode::MemberNotSuspended);
        }

        return AccountSuspension::query()
            ->where('user_id', $user->id)
            ->inForce()
            ->latest('suspended_at')
            ->first()
            ?? throw DomainException::of(ErrorCode::MemberNotSuspended);
    }

    private function standing(User $user): JsonResponse
    {
        $fresh = User::query()->findOrFail($user->id);
        $suspended = $fresh->account_status === AccountStatus::Suspended;

        return ApiResponse::success([
            'accountStatus' => strtoupper($fresh->account_status->value),
            'nextStep' => LaunchRouter::nextStepFor($fresh)->value,
            'suspension' => $suspended ? SuspensionSummary::forMember($fresh) : null,
        ]);
    }

    private function reviewer(): AdminUser
    {
        return AdminUser::query()->orderBy('created_at')->first()
            ?? throw DomainException::of(ErrorCode::ServiceUnavailable);
    }
}
