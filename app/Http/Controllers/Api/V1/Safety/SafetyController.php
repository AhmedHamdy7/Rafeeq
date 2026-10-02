<?php

namespace App\Http\Controllers\Api\V1\Safety;

use App\Domains\Safety\Actions\AttachIncidentEvidenceAction;
use App\Domains\Safety\Actions\BlockUserAction;
use App\Domains\Safety\Actions\ManageEmergencyContactsAction;
use App\Domains\Safety\Actions\ReportIncidentAction;
use App\Domains\Safety\Actions\ShareLiveTripAction;
use App\Domains\Safety\Actions\TriggerSosAction;
use App\Domains\Safety\Models\BlockedUser;
use App\Domains\Safety\Models\EmergencyContact;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\ValueObjects\Coordinate;
use App\Domains\Trip\Models\TripSession;
use App\Http\Controllers\Controller;
use App\Http\OpenApi\ApiErrors;
use App\Http\Requests\Safety\AttachIncidentEvidenceRequest;
use App\Http\Requests\Safety\BlockUserRequest;
use App\Http\Requests\Safety\ReportIncidentRequest;
use App\Http\Requests\Safety\ShareLiveTripRequest;
use App\Http\Requests\Safety\StoreEmergencyContactRequest;
use App\Http\Requests\Safety\TriggerSosRequest;
use App\Http\Resources\EmergencyContactResource;
use App\Http\Resources\IncidentEvidenceResource;
use App\Http\Resources\IncidentResource;
use App\Http\Resources\LiveShareResource;
use App\Http\Resources\PersonSummary;
use App\Http\Resources\SosEventResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Safety Centre (Chapter 10, screens 16, 26, 32, 44).
 *
 * 🔴 **Everything here is reachable by a signed-in account and nothing more.** No verification gate,
 * no complete-profile requirement, no active trip. That is the most important decision in this
 * controller and it is deliberate: somebody in trouble at a roadside will not finish uploading a
 * national ID first, and a 403 at that moment would be the worst answer this platform could give.
 * The routes sit in the `signed-in` tier alongside "see that I am suspended" and "revoke a stolen
 * phone", for the same reason — these are the things a person in a bad state still needs.
 *
 * 🔒 And the whole of it is scoped to the caller. There is no endpoint here that reads another
 * person's contacts, another person's reports, or who has blocked whom.
 */
final class SafetyController extends Controller
{
    /**
     * POST /v1/sos — the emergency button.
     *
     * 🔴 The row is written the moment this is called, BEFORE any countdown finishes. The countdown
     * runs on the phone and guards against an accidental tap; it does not gate the record. If the
     * phone is taken or its battery dies during those ten seconds, a design that waited for
     * confirmation would have no trace that anything happened. See `TriggerSosAction`.
     *
     * Answers 201 with the SOS so the client can show the cancel affordance and count down against
     * the window that was actually in force.
     */
    public function triggerSos(TriggerSosRequest $request, TriggerSosAction $action): JsonResponse
    {
        $sos = $action->execute(
            user: $request->user(),
            isDiscreet: $request->boolean('isDiscreet'),
            at: $this->reportedPosition($request),
            session: $this->namedSession($request),
        );

        return ApiResponse::success(new SosEventResource($sos), status: 201);
    }

    /**
     * POST /v1/sos/{sos}/cancel — "it was an accident".
     *
     * 🔒 Nothing is deleted. The row stays with a cancellation time on it, because a pattern of
     * presses cancelled seconds later — same route, same driver — is exactly the signal a safety
     * team needs, and it is invisible if each one erases itself.
     */
    #[ApiErrors(ErrorCode::SosAlreadyResolved, ErrorCode::NotFound)]
    public function cancelSos(Request $request, string $sos, TriggerSosAction $action): JsonResponse
    {
        $event = SosEvent::query()->whereKey($sos)->with('safetyEvent')->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new SosEventResource($action->cancel($request->user(), $event)));
    }

    /**
     * GET /v1/safety/emergency-contacts — the caller's own trusted contacts.
     */
    public function contacts(Request $request): JsonResponse
    {
        $contacts = EmergencyContact::query()
            ->where('user_id', $request->user()->id)
            ->oldest('created_at')
            ->orderBy('id')
            ->get();

        return ApiResponse::success(EmergencyContactResource::collection($contacts));
    }

    /**
     * POST /v1/safety/emergency-contacts — add one.
     */
    #[ApiErrors(
        ErrorCode::EmergencyContactLimitReached,
        ErrorCode::EmergencyContactDuplicate,
        ErrorCode::PhoneInvalid,
    )]
    public function addContact(StoreEmergencyContactRequest $request, ManageEmergencyContactsAction $action): JsonResponse
    {
        $contact = $action->add($request->user(), $request->validated());

        return ApiResponse::success(new EmergencyContactResource($contact), status: 201);
    }

    /**
     * PATCH /v1/safety/emergency-contacts/{contact} — change one.
     */
    #[ApiErrors(
        ErrorCode::EmergencyContactDuplicate,
        ErrorCode::PhoneInvalid,
        ErrorCode::NotFound,
    )]
    public function updateContact(
        StoreEmergencyContactRequest $request,
        string $contact,
        ManageEmergencyContactsAction $action,
    ): JsonResponse {
        $own = $this->ownContact($request, $contact);

        return ApiResponse::success(new EmergencyContactResource(
            $action->update($own, $request->validated())
        ));
    }

    /**
     * DELETE /v1/safety/emergency-contacts/{contact} — remove one.
     *
     * 🔒 Immediate and unconditional. Somebody removing a contact may be doing it quickly and
     * quietly, and every extra step is a step taken while they may be watched.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function removeContact(Request $request, string $contact, ManageEmergencyContactsAction $action): JsonResponse
    {
        $action->remove($this->ownContact($request, $contact));

        return ApiResponse::success(['removed' => true]);
    }

    /**
     * POST /v1/incidents — file a report.
     */
    #[ApiErrors(ErrorCode::IncidentNotReportable, ErrorCode::TooManyRequests)]
    public function reportIncident(ReportIncidentRequest $request, ReportIncidentAction $action): JsonResponse
    {
        $incident = $action->execute($request->user(), $request->validated());

        return ApiResponse::success(new IncidentResource($incident), status: 201);
    }

    /**
     * GET /v1/incidents — the caller's own reports, newest first.
     */
    public function incidents(Request $request): JsonResponse
    {
        $incidents = Incident::query()
            ->where('reporter_user_id', $request->user()->id)
            ->withCount('evidence')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($incidents, IncidentResource::collection($incidents->items()));
    }

    /**
     * GET /v1/incidents/{incident} — one of the caller's own reports.
     *
     * 🔒 404 for anybody else's, including the person it was about. A report is not a conversation
     * between the two parties — showing the subject what was said about them, in their own words,
     * is how a report becomes a reason for a confrontation.
     */
    #[ApiErrors(ErrorCode::NotFound)]
    public function showIncident(Request $request, string $incident): JsonResponse
    {
        $own = $this->ownIncident($request, $incident);

        return ApiResponse::success(new IncidentResource($own->load('evidence')));
    }

    /**
     * POST /v1/incidents/{incident}/evidence — attach a photograph.
     *
     * 🔒 The file is scanned, then re-encoded to strip its metadata, then stored on the private
     * disk — in that order, through the same intake identity documents use. A photograph taken at
     * the scene carries the GPS of where the person was standing when they were frightened, and
     * they photographed a car, not their own position.
     *
     * 🔴 **Nothing can be un-attached.** `incident_evidence` is never deleted — it is chain of
     * custody — so a client must confirm before uploading rather than offering a remove button it
     * cannot honour.
     */
    #[ApiErrors(
        ErrorCode::IncidentClosed,
        ErrorCode::IncidentEvidenceLimitReached,
        ErrorCode::DocumentRejectedByScanner,
        ErrorCode::DocumentUnreadable,
        ErrorCode::DocumentDimensionsTooLarge,
        ErrorCode::NotFound,
    )]
    public function attachEvidence(
        AttachIncidentEvidenceRequest $request,
        string $incident,
        AttachIncidentEvidenceAction $action,
    ): JsonResponse {
        $evidence = $action->execute(
            $request->user(),
            $this->ownIncident($request, $incident),
            $request->file('file'),
            $request->kind(),
        );

        return ApiResponse::success(new IncidentEvidenceResource($evidence), status: 201);
    }

    /**
     * GET /v1/incidents/{incident}/evidence — what is attached to one of the caller's own reports.
     *
     * 🔒 Metadata only. See `IncidentEvidenceResource`: no path, no URL, no hash.
     */
    public function evidence(Request $request, string $incident): JsonResponse
    {
        $own = $this->ownIncident($request, $incident);

        return ApiResponse::success(IncidentEvidenceResource::collection(
            $own->evidence()->oldest('created_at')->orderBy('id')->get()
        ));
    }

    /**
     * POST /v1/trips/{trip}/live-share — "Share Live Trip".
     *
     * 🔴 The response carries the token and the URL **once**. Nothing can reproduce them
     * afterwards: only a hash is stored, and re-reading the share gives its view count rather
     * than its link. A client that loses the URL creates a new share.
     */
    #[ApiErrors(
        ErrorCode::TripNotStarted,
        ErrorCode::NotFound,
    )]
    public function shareLiveTrip(ShareLiveTripRequest $request, string $trip, ShareLiveTripAction $action): JsonResponse
    {
        $session = TripSession::query()->where('scheduled_trip_id', $trip)->first()
            ?? throw DomainException::of(ErrorCode::TripNotStarted);

        $contactId = $request->input('contactId');

        /*
         * 🔒 Looked up UNSCOPED and handed over, so the Action makes the ownership call in one
         * place — and a named id that matches nothing is a 404 rather than a share quietly created
         * with no contact on it, which would tell the caller the id was wrong just as clearly
         * while leaving a link behind.
         */
        $contact = $contactId === null
            ? null
            : EmergencyContact::query()->whereKey($contactId)->first()
                ?? throw DomainException::of(ErrorCode::NotFound);

        $result = $action->create($request->user(), $session, $contact);

        return ApiResponse::success([
            ...(new LiveShareResource($result['share']))->toArray($request),
            /*
             * 🔒 The only time either of these is ever sent. The URL is built here rather than
             * handed over as a bare token, so every client produces the same link and none of
             * them invents a path.
             */
            'url' => url('/s/'.$result['token']),
            'token' => $result['token'],
        ], status: 201);
    }

    /**
     * GET /v1/safety/live-shares — the caller's own share links.
     *
     * 🔒 Without tokens. See LiveShareResource: being able to re-read them would mean a stolen
     * access token could harvest every link a person ever made.
     */
    public function liveShares(Request $request): JsonResponse
    {
        $shares = LiveShare::query()
            ->where('user_id', $request->user()->id)
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        return ApiResponse::paginated($shares, LiveShareResource::collection($shares->items()));
    }

    /**
     * DELETE /v1/safety/live-shares/{share} — stop it now.
     *
     * Takes effect on the next view rather than waiting for the expiry, because somebody revoking
     * a share has usually just decided they do not want it open any more.
     */
    #[ApiErrors(ErrorCode::LiveShareAlreadyEnded, ErrorCode::NotFound)]
    public function revokeLiveShare(Request $request, string $share, ShareLiveTripAction $action): JsonResponse
    {
        $own = LiveShare::query()->whereKey($share)->first()
            ?? throw DomainException::of(ErrorCode::NotFound);

        return ApiResponse::success(new LiveShareResource(
            $action->revoke($request->user(), $own)
        ));
    }

    /**
     * GET /v1/safety/blocked-users — who the caller has blocked.
     *
     * 🔒 One direction only: who I blocked, never who blocked me. The second would tell somebody
     * they have been blocked, which is the one thing a block must never do.
     */
    public function blocked(Request $request): JsonResponse
    {
        $blocks = BlockedUser::query()
            ->where('blocker_user_id', $request->user()->id)
            ->with('blocked.stats', 'blocked.verifications')
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage($request));

        $rows = [];

        foreach ($blocks->items() as $block) {
            $rows[] = [
                'id' => $block->id,
                'userId' => $block->blocked_user_id,
                // The public summary, so the list is recognisable without naming anybody fully.
                'person' => PersonSummary::for($block->blocked, $request->user()),
                'reason' => $block->reason,
                'createdAt' => $block->created_at->toIso8601String(),
            ];
        }

        return ApiResponse::paginated($blocks, $rows);
    }

    /**
     * POST /v1/safety/blocked-users — block somebody.
     *
     * The matching engine has excluded blocked pairs in BOTH directions since Phase 6; this writes
     * the row it reads. Existing bookings are untouched — "existing completed history remains".
     */
    #[ApiErrors(ErrorCode::CannotBlockSelf, ErrorCode::AlreadyBlocked, ErrorCode::NotFound)]
    public function block(BlockUserRequest $request, BlockUserAction $action): JsonResponse
    {
        $block = $action->block(
            $request->user(),
            $request->string('userId')->value(),
            $request->input('reason'),
        );

        return ApiResponse::success(['id' => $block->id, 'userId' => $block->blocked_user_id], status: 201);
    }

    /**
     * DELETE /v1/safety/blocked-users/{user} — unblock.
     */
    public function unblock(Request $request, string $user, BlockUserAction $action): JsonResponse
    {
        $action->unblock($request->user(), $user);

        return ApiResponse::success(['unblocked' => true]);
    }

    private function reportedPosition(Request $request): ?Coordinate
    {
        if ($request->input('lat') === null) {
            return null;
        }

        return new Coordinate((float) $request->input('lat'), (float) $request->input('lng'));
    }

    /**
     * The run the client named, if it named a real one.
     *
     * 🔴 A wrong or unknown id is IGNORED rather than refused. This is the SOS path: a client sending
     * a stale session id must not turn an emergency into a 404. The Action looks the run up itself
     * when this returns null.
     */
    private function namedSession(Request $request): ?TripSession
    {
        $id = $request->input('tripSessionId');

        return $id === null ? null : TripSession::query()->whereKey($id)->first();
    }

    /**
     * 🔒 One of the caller's OWN reports, or a 404 — including for the person the report is about.
     *
     * The same scoping `showIncident` uses, in one place because three endpoints now need it and a
     * second copy is a second place the `reporter_user_id` clause can be left off.
     */
    private function ownIncident(Request $request, string $incidentId): Incident
    {
        return Incident::query()
            ->whereKey($incidentId)
            ->where('reporter_user_id', $request->user()->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }

    private function ownContact(Request $request, string $contactId): EmergencyContact
    {
        return EmergencyContact::query()
            ->whereKey($contactId)
            ->where('user_id', $request->user()->id)
            ->first()
            ?? throw DomainException::of(ErrorCode::NotFound);
    }
}
