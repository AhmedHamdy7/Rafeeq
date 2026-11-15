<?php

namespace App\Http\Controllers\Api\V1\Testing;

use App\Domains\Safety\Models\AutoShareMessage;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\TestAccounts;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TestingSafetyController extends Controller
{
    #[ApiErrors(ErrorCode::NotFound)]
    public function autoShareMessages(Request $request): JsonResponse
    {
        $user = TestAccounts::assertAllowed($request->user());

        $latest = AutoShareMessage::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null) {
            return ApiResponse::success([], meta: ['tripId' => null, 'tripSessionId' => null]);
        }

        $messages = AutoShareMessage::query()
            ->where('user_id', $user->id)
            ->where('trip_session_id', $latest->trip_session_id)
            ->with('tripSession')
            ->oldest('created_at')
            ->orderBy('id')
            ->get();

        $rows = $messages->map(fn (AutoShareMessage $message): array => [
            'contactId' => $message->emergency_contact_id,
            'liveShareId' => $message->live_share_id,
            'channel' => strtoupper($message->channel),
            'to' => $message->to_e164,
            'status' => strtoupper($message->status->value),
            'failureReason' => $message->failure_reason,
            'url' => $message->preview_url,
            'sentAt' => $message->sent_at?->toIso8601String(),
        ])->all();

        return ApiResponse::success($rows, meta: [
            'tripId' => $messages->first()->tripSession?->scheduled_trip_id,
            'tripSessionId' => $latest->trip_session_id,
        ]);
    }
}
