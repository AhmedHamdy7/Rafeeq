<?php

namespace App\Http\Controllers;

use App\Domains\Commute\Enums\CommuteLocationType;
use App\Domains\Safety\Actions\ShareLiveTripAction;
use App\Domains\Trip\Actions\RecordTripLocationAction;
use Illuminate\Http\Response;

/**
 * The page a trusted contact opens (Chapter 10, "Share Live Trip").
 *
 * 🔴 The only route in the application that serves real data with NO authentication. The token is
 * the whole credential, so this controller is written as if the URL were public — because for
 * practical purposes it is: it will be pasted into WhatsApp, forwarded, and screenshotted.
 *
 * 🔒 What that means for what the page can say. The viewer is a stranger to the DRIVER — she never
 * agreed to share anything with this person, and the passenger cannot consent on her behalf. So
 * the page carries what somebody needs to act in an emergency and nothing that is still useful
 * tomorrow:
 *
 *   shown     — the driver's public first name, the car (so it can be identified or described to
 *               police), where it is now, where it is going, when it is due
 *   not shown — anybody's phone number, full name or home address · the other passengers, who did
 *               not consent to being named to this viewer at all · the GPS history · any id that
 *               could be used against another endpoint
 *
 * The plate is included, and it is the one judgement here worth stating. It identifies the
 * driver's car to somebody with no relationship to her, which is a real cost. It is included
 * because without it the feature does not do its job: a contact who cannot say which car somebody
 * is in has nothing to give anyone. A live share during an active journey, to one person, is the
 * narrowest form that still works.
 */
final class LiveShareController extends Controller
{
    /**
     * GET /s/{token}
     *
     * Deliberately unauthenticated and deliberately not under `/v1`: this is a web page for a
     * person, not an endpoint for the app.
     */
    public function show(string $token, ShareLiveTripAction $shares, RecordTripLocationAction $locations): Response
    {
        $share = $shares->resolve($token);

        /*
         * 🔒 One page for expired, revoked and never-existed alike. Distinguishing them would
         * confirm that a guessed token was once real, which turns a 404 into an oracle — and the
         * tokens being guessed would be other people's journeys.
         */
        if ($share === null) {
            return $this->headers(response()->view('live-share.unavailable', [], 404));
        }

        $session = $share->tripSession;
        $trip = $session->scheduledTrip;
        $offer = $trip->commuteOffer;

        $destination = $offer->locations->firstWhere('type', CommuteLocationType::Destination);
        $position = $locations->current($session);

        return $this->headers(response()->view('live-share.show', [
            // A public first name and nothing else about the person.
            'driverName' => $offer->driverProfile->user->public_first_name,

            'vehicle' => [
                'make' => $offer->vehicle?->make,
                'model' => $offer->vehicle?->model,
                'colour' => $offer->vehicle?->colour,
                // See the class note on why the plate is here.
                'plate' => $offer->vehicle?->plate_number,
            ],

            /*
             * Where it is NOW, or null when the run has gone quiet. Null is shown as "no recent
             * position" rather than hidden: a contact looking at a stale dot would believe the car
             * had stopped there.
             */
            'position' => $position?->toArray(),
            'lastSeenAt' => $session->last_location_at,

            'destinationLabel' => $destination?->address,
            'dueAt' => $trip->departure_at,
            'isUnderway' => $session->current_status->isUnderway(),

            // So the person who shared it knows the link is live and for how long.
            'expiresAt' => $share->expires_at,
        ]));
    }

    /**
     * 🔒 The two headers the Bible requires on this page by name, plus the ones that follow from
     * the same reasoning.
     *
     * `noindex` because a forwarded link ending up in a search index would turn a private share
     * into a published one, permanently and for everybody. `no-store` because a shared phone, a
     * corporate proxy or a browser cache holding this page means it outlives its own expiry — the
     * one property the whole design depends on.
     */
    private function headers(Response $response): Response
    {
        return $response
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            // A referrer would leak the token to whatever the page links to or loads.
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Frame-Options', 'DENY')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
