<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Actions\RecordConsentAction;
use App\Domains\Identity\Enums\ConsentDocumentType;
use App\Domains\Identity\Enums\ConsentSource;
use App\Domains\Identity\Models\UserConsent;
use App\Domains\Identity\Support\ConsentRegistry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RecordConsentRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Consent versioning (Phase 2 scope in the Master Plan).
 *
 * Publishing new terms is a version bump in configuration; every account's
 * consent then reads as outstanding until it is accepted again, and the
 * previous acceptance stays on file as the evidence of what was agreed
 * when.
 */
final class ConsentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->state($request));
    }

    public function store(RecordConsentRequest $request, RecordConsentAction $action): JsonResponse
    {
        $type = $request->type();

        $type === ConsentDocumentType::Biometric
            ? $action->execute($request->user(), ConsentSource::Settings, [ConsentDocumentType::Biometric])
            : $action->execute($request->user(), ConsentSource::ForcedReaccept);

        return ApiResponse::success($this->state($request));
    }

    private function state(Request $request): array
    {
        $version = ConsentRegistry::currentVersion(ConsentDocumentType::Biometric);

        $accepted = UserConsent::query()
            ->where('user_id', $request->user()->id)
            ->where('document_type', ConsentDocumentType::Biometric->value)
            ->where('document_version', $version)
            ->whereNull('withdrawn_at')
            ->value('accepted_at');

        return [
            'currentVersions' => ConsentRegistry::currentVersions(),
            'outstanding' => RecordConsentAction::outstandingFor($request->user()),
            'biometric' => [
                'version' => $version,
                'acceptedAt' => $accepted === null ? null : Carbon::parse($accepted)->toIso8601String(),
            ],
        ];
    }
}
