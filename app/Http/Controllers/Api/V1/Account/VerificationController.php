<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\Organization;
use App\Domains\Identity\Support\SecurityLog;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Verification\Actions\SubmitVerificationAction;
use App\Domains\Verification\Actions\UploadVerificationDocumentAction;
use App\Domains\Verification\Actions\VerifyOrganizationByEmailAction;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\IdentityDocument;
use App\Domains\Verification\Support\DocumentStorage;
use App\Domains\Verification\Support\VerificationCentre;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Account\UploadVerificationDocumentRequest;
use App\Http\Requests\Account\VerifyOrganizationRequest;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class VerificationController extends Controller
{
    /**
     * GET /v1/account/verifications — the Verification Centre
     * (MASTER_PLAN §247): progress plus one row per level.
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(VerificationCentre::for($request->user()));
    }

    /**
     * POST /v1/account/verifications/{type}/documents — Chapter 3 §5/§8.
     *
     * The upload is scanned, stripped of metadata and re-encoded before it is
     * stored; see the Action for why in that order.
     */
    #[ApiErrors(
        ErrorCode::DocumentKindNotAccepted,
        ErrorCode::DocumentRejectedByScanner,
        ErrorCode::DocumentUnreadable,
        ErrorCode::VerificationAlreadyApproved,
        ErrorCode::VerificationAttemptsExhausted,
        ErrorCode::NotFound,
    )]
    public function storeDocument(
        UploadVerificationDocumentRequest $request,
        string $type,
        UploadVerificationDocumentAction $action,
    ): JsonResponse {
        $document = $action->execute(
            user: $request->user(),
            type: $this->verificationType($type),
            kind: $request->kind(),
            file: $request->file('file'),
        );

        return ApiResponse::success([
            'id' => $document->id,
            'kind' => $document->kind->value,
            'scanStatus' => strtoupper($document->virus_scan_status->value),
            // Returned so the app can show a thumbnail of what it just sent.
            // Short-lived and re-issued on every read — never a stable URL.
            'url' => app(DocumentStorage::class)->temporaryUrl($document),
            'verification' => VerificationCentre::for($request->user()->refresh()),
        ], status: 201);
    }

    /**
     * POST /v1/account/verifications/{type}/submit — hands the level to the
     * review queue (Chapter 3 §9).
     */
    #[ApiErrors(
        ErrorCode::VerificationNotSubmittable,
        ErrorCode::VerificationAlreadyApproved,
        ErrorCode::VerificationAttemptsExhausted,
        ErrorCode::NotFound,
    )]
    public function submit(Request $request, string $type, SubmitVerificationAction $action): JsonResponse
    {
        $action->execute($request->user(), $this->verificationType($type));

        return ApiResponse::success(VerificationCentre::for($request->user()->refresh()));
    }

    /**
     * POST /v1/account/verifications/organization — level 4 without a
     * reviewer, for a proven work or university email domain.
     */
    #[ApiErrors(
        ErrorCode::OrganizationEmailMismatch,
        ErrorCode::VerificationAlreadyApproved,
    )]
    public function verifyOrganization(
        VerifyOrganizationRequest $request,
        VerifyOrganizationByEmailAction $action,
    ): JsonResponse {
        $action->execute(
            user: $request->user(),
            organization: Organization::findOrFail($request->validated('organizationId')),
            email: $request->validated('email'),
        );

        return ApiResponse::success(VerificationCentre::for($request->user()->refresh()));
    }

    /**
     * GET /v1/account/verifications/documents/{document} — streams one of the
     * caller's OWN documents from the private disk.
     *
     * Reached by a short-lived signed URL, and it still checks ownership when
     * called: a signature proves the link was issued by us and has not
     * expired, not that the person holding it is the person it was issued to.
     * Pitfall #23 — a document is never served from a public URL.
     */
    public function showDocument(Request $request, string $document): StreamedResponse
    {
        $identityDocument = IdentityDocument::query()
            ->with('verification')
            ->whereKey($document)
            ->first();

        // 404 for someone else's document, never 403: a 403 would confirm the
        // id exists, which turns this into an enumeration oracle.
        if ($identityDocument === null
            || $identityDocument->verification->user_id !== $request->user()?->id) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        SecurityLog::record(SecurityEventType::DocumentAccessed, $request->user(), metadata: [
            'document_id' => $identityDocument->id,
        ]);

        return app(DocumentStorage::class)->disk()->response(
            $identityDocument->file_path,
            headers: [
                // `attachment` and a strict CSP: the file is untrusted content
                // stored on our origin, so it must never be rendered inline
                // where it could execute against our own session.
                'Content-Disposition' => 'attachment',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * An unknown level is a 404, not a validation error: the value is part of
     * the path, so a bad one names a resource that does not exist.
     */
    private function verificationType(string $type): VerificationType
    {
        return VerificationType::tryFrom($type)
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
