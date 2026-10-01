<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Safety\Enums\SafetyEventType;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Safety\Models\SafetyEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * "Share Live Trip" (Chapter 10) — a temporary link a trusted contact opens.
 *
 * 🔴 The whole feature is a URL that bypasses authentication, so almost every test here bounds it
 * rather than proving it works. The token is the entire credential, and the link will be pasted
 * into WhatsApp, forwarded and screenshotted — so it is treated as public.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->driverToken = approvedDriver(phone: '01012345678', devicePublicId: 'driver-1');
    $this->commuteId = readyCommute($this->driverToken, Vehicle::sole()->id);

    test()->withToken($this->driverToken)
        ->postJson("/api/v1/commutes/{$this->commuteId}/publish")->assertOk();

    $this->tripId = ScheduledTrip::query()->orderBy('trip_date')->first()->id;

    $this->paxToken = verifiedPassenger('01112223344');

    approveSeat($this->driverToken, requestSeat($this->paxToken, $this->commuteId, [
        'scheduledTripId' => $this->tripId,
    ])->assertStatus(201)->json('data.id'));
});

function shareTrip(string $token, string $tripId, array $payload = [])
{
    return test()->withToken($token)->postJson("/api/v1/trips/{$tripId}/live-share", $payload);
}

/** Somebody with no connection to this journey at all. */
function unrelatedAccount(): string
{
    return verifiedPassenger('01223339999', device: 'pax-2');
}

/*
|--------------------------------------------------------------------------
| Creating a link
|--------------------------------------------------------------------------
*/

it('gives a passenger on the run a link to share', function () {
    underway($this->driverToken, $this->tripId);

    $share = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data');

    expect($share['url'])->toContain('/s/')
        ->and($share['token'])->not->toBeEmpty()
        ->and($share['isActive'])->toBeTrue()
        ->and($share['viewCount'])->toBe(0)
        ->and($share['lastViewedAt'])->toBeNull();
});

it('lets the driver share her own run too', function () {
    underway($this->driverToken, $this->tripId);

    shareTrip($this->driverToken, $this->tripId)->assertStatus(201);
});

/**
 * 🔒 Pitfall #29. A database leak must yield no working links, and a sequential id would let
 * anybody enumerate other people's journeys.
 */
it('stores only a hash of the token, never the token', function () {
    underway($this->driverToken, $this->tripId);

    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    $share = LiveShare::sole();

    expect(strlen($token))->toBeGreaterThanOrEqual(32)
        ->and($share->token_hash)->toBe(hash('sha256', $token))
        ->and($share->token_hash)->not->toBe($token)
        // Hidden on the model too, so forgetting it in one resource is not enough to leak it.
        ->and($share->toArray())->not->toHaveKey('token_hash');
});

/**
 * 🔴 The plaintext is returned exactly once. Being able to re-read it would mean a stolen access
 * token could harvest every share link a person ever made.
 */
it('never returns the token again after it is created', function () {
    underway($this->driverToken, $this->tripId);

    shareTrip($this->paxToken, $this->tripId)->assertStatus(201);

    $listed = test()->withToken($this->paxToken)->getJson('/api/v1/safety/live-shares')
        ->assertOk()->json('data.0');

    expect($listed)->not->toHaveKey('token')
        ->and($listed)->not->toHaveKey('url')
        ->and($listed)->not->toHaveKey('tokenHash')
        // What you do get back is the thing you actually opened the screen for.
        ->and($listed)->toHaveKeys(['isActive', 'viewCount', 'lastViewedAt', 'expiresAt']);
});

/**
 * 🔒 Without this, anybody who learned a trip id could publish a link to a stranger's journey.
 */
it('refuses to share a run the caller is not on', function () {
    underway($this->driverToken, $this->tripId);

    shareTrip(unrelatedAccount(), $this->tripId)->assertStatus(404);

    expect(LiveShare::count())->toBe(0);
});

it('refuses to share a run that has not left', function () {
    runLeavingIn($this->tripId, 10);
    test()->withToken($this->driverToken)->postJson("/api/v1/trips/{$this->tripId}/start")->assertStatus(201);

    // A link made before the car moves would be a page saying nothing.
    shareTrip($this->paxToken, $this->tripId)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'TRIP_NOT_STARTED')
        ->assertJsonPath('error.fields.tripStatus.0', 'PREPARING');
});

it('refuses to share a run that was never started', function () {
    shareTrip($this->paxToken, $this->tripId)->assertStatus(409);

    expect(LiveShare::count())->toBe(0);
});

it('records a share against a named contact', function () {
    $contactId = test()->withToken($this->paxToken)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'هالة',
        'phone' => '01098765432',
    ])->assertStatus(201)->json('data.id');

    underway($this->driverToken, $this->tripId);

    expect(shareTrip($this->paxToken, $this->tripId, ['contactId' => $contactId])
        ->assertStatus(201)->json('data.sharedWithContactId'))->toBe($contactId);
});

/**
 * 🔒 404-shaped, and for an unknown id as well as somebody else's: a contact id must not be
 * probeable through this endpoint, and a share quietly created with no contact on it would tell
 * the caller the id was wrong just as clearly.
 */
it('refuses a contact id that is not the caller own', function () {
    $other = unrelatedAccount();

    $theirContact = test()->withToken($other)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => 'Theirs',
        'phone' => '01098765432',
    ])->assertStatus(201)->json('data.id');

    underway($this->driverToken, $this->tripId);

    shareTrip($this->paxToken, $this->tripId, ['contactId' => $theirContact])->assertStatus(404);
    shareTrip($this->paxToken, $this->tripId, ['contactId' => str_repeat('0', 26)])->assertStatus(404);

    expect(LiveShare::count())->toBe(0);
});

/**
 * 🔒 How long a link lives is policy, not the client's. A share that outlived its journey would be
 * a standing window onto wherever that person goes next.
 */
it('gives the client no say in how long the link lives', function () {
    underway($this->driverToken, $this->tripId);

    $share = shareTrip($this->paxToken, $this->tripId, [
        // Ignored: there is no such field on the request.
        'expiresAt' => now()->addYear()->toIso8601String(),
    ])->assertStatus(201)->json('data');

    // The shipped ceiling is 180 minutes plus a 15-minute grace.
    expect(CarbonImmutable::parse($share['expiresAt'])->diffInMinutes(now(), absolute: true))
        ->toBeLessThan(200);
});

it('follows the ceiling in settings rather than the one it shipped with', function () {
    PlatformSetting::query()->create([
        'setting_key' => 'safety.live_share_max_minutes',
        'setting_value' => 30,
        'value_type' => 'integer',
        'description' => 'A shorter ceiling',
    ]);

    underway($this->driverToken, $this->tripId);

    $expiresAt = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.expiresAt');

    expect(CarbonImmutable::parse($expiresAt)->diffInMinutes(now(), absolute: true))
        ->toBeLessThan(50);
});

it('writes the share into the record that is never deleted', function () {
    underway($this->driverToken, $this->tripId);

    shareTrip($this->paxToken, $this->tripId)->assertStatus(201);

    $event = SafetyEvent::query()->where('type', SafetyEventType::LiveShareStarted->value)->sole();

    // Low, not high: somebody taking a sensible precaution is the ordinary case, and marking it as
    // an emergency would bury the real alerts among them.
    expect($event->severity->value)->toBe('low')
        ->and($event->metadata['liveShareId'])->toBe(LiveShare::sole()->id);
});

/*
|--------------------------------------------------------------------------
| The page itself — the part with no authentication
|--------------------------------------------------------------------------
*/

it('shows the contact the car, where it is and where it is going', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position(lat: 30.0666, lng: 31.2345)])->assertOk();

    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    // No credential of any kind.
    test()->withoutToken()->get("/s/{$token}")->assertOk()
        ->assertSee('مريم', escape: false)             // the driver's public first name
        ->assertSee('Smart Village B6', escape: false) // where it is going
        ->assertSee('ABC', escape: false)              // the plate
        ->assertSee('30.0666', escape: false);         // where it is now
});

/**
 * 🔒 The viewer is a stranger to the DRIVER. She never agreed to share anything with this person,
 * and the passenger cannot consent on her behalf. So the page carries what somebody needs to act in
 * an emergency and nothing that is still useful to them tomorrow.
 */
it('tells the contact nothing it does not need', function () {
    underway($this->driverToken, $this->tripId);
    reportPosition($this->driverToken, $this->tripId, [position()])->assertOk();

    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    $body = test()->withoutToken()->get("/s/{$token}")->assertOk()->getContent();

    expect($body)
        // Nobody's phone number, in either form the system stores it.
        ->not->toContain('01012345678')
        ->not->toContain('01112223344')
        ->not->toContain('+2010')
        ->not->toContain('+2011')
        // Nor anybody's full name — the driver appears by her first name only.
        ->not->toContain('مريم حسن')
        /*
         * 🔒 And not the other passengers. They did not consent to being named to this viewer at
         * all; the page is about one car, not about who is in it.
         */
        ->not->toContain('سارة')
        // No ids that could be pointed at another endpoint.
        ->not->toContain($this->tripId)
        ->not->toContain($this->commuteId);
});

/**
 * 🔒 The two headers the Bible names, plus the ones that follow from the same reasoning. `noindex`
 * because a forwarded link in a search index turns a private share into a published one,
 * permanently; `no-store` because a cache holding this page means it outlives its own expiry — the
 * one property the whole design rests on.
 */
it('tells every cache and crawler to forget the page', function () {
    underway($this->driverToken, $this->tripId);
    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    $page = test()->withoutToken()->get("/s/{$token}")->assertOk();

    expect($page->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($page->headers->get('Cache-Control'))->toContain('no-store')
        // A referrer would leak the token — which IS the credential — to anything the page loads.
        ->and($page->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($page->headers->get('X-Frame-Options'))->toBe('DENY');
});

it('sends the same headers on the page that says nothing', function () {
    // 🔒 The unavailable page must not be cacheable either: a cached "not available" would survive
    // a new share, and a crawler following a forwarded dead link is still a crawler holding one.
    $page = test()->withoutToken()->get('/s/'.str_repeat('a', 43))->assertStatus(404);

    expect($page->headers->get('X-Robots-Tag'))->toContain('noindex')
        ->and($page->headers->get('Cache-Control'))->toContain('no-store')
        ->and($page->headers->get('Referrer-Policy'))->toBe('no-referrer');
});

it('counts every view, so the sharer knows somebody looked', function () {
    underway($this->driverToken, $this->tripId);
    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    test()->withoutToken()->get("/s/{$token}")->assertOk();
    test()->withoutToken()->get("/s/{$token}")->assertOk();

    // 🔴 Not analytics: for somebody who shared the link because they felt uneasy, "did they look"
    // is the question they open the screen to answer.
    $listed = test()->withToken($this->paxToken)->getJson('/api/v1/safety/live-shares')
        ->assertOk()->json('data.0');

    expect($listed['viewCount'])->toBe(2)
        ->and($listed['lastViewedAt'])->not->toBeNull();
});

it('says so plainly when the car has gone quiet', function () {
    underway($this->driverToken, $this->tripId);

    // No position reported at all.
    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    // 🔒 A contact looking at a stale dot would believe the car had stopped there — the one wrong
    // conclusion this page could lead somebody to in an emergency.
    test()->withoutToken()->get("/s/{$token}")->assertOk()
        ->assertSee('مفيش تحديث حديث', escape: false);
});

/*
|--------------------------------------------------------------------------
| Ending a link
|--------------------------------------------------------------------------
*/

it('stops working when it is revoked', function () {
    underway($this->driverToken, $this->tripId);
    $share = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data');

    test()->withToken($this->paxToken)
        ->deleteJson("/api/v1/safety/live-shares/{$share['id']}")
        ->assertOk()
        ->assertJsonPath('data.isActive', false);

    test()->withoutToken()->get("/s/{$share['token']}")->assertStatus(404);
});

it('stops working once it has expired', function () {
    underway($this->driverToken, $this->tripId);
    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    // Moved on the row rather than by travelling hours, which would expire the access token.
    LiveShare::query()->update(['expires_at' => now()->subMinute()]);

    test()->withoutToken()->get("/s/{$token}")->assertStatus(404);
});

/**
 * 🔒 One page for expired, revoked and never-existed alike. Distinguishing them would confirm that
 * a guessed token was once real — turning the page into an oracle about other people's journeys.
 */
it('answers an unknown token exactly like a dead one', function () {
    underway($this->driverToken, $this->tripId);
    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    LiveShare::query()->update(['revoked_at' => now()]);

    $revoked = test()->withoutToken()->get("/s/{$token}");
    $neverExisted = test()->withoutToken()->get('/s/'.str_repeat('a', 43));

    expect($revoked->status())->toBe($neverExisted->status())
        ->and($revoked->getContent())->toBe($neverExisted->getContent());
});

it('does not count a view on a link that no longer works', function () {
    underway($this->driverToken, $this->tripId);
    $token = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.token');

    LiveShare::query()->update(['revoked_at' => now()]);

    test()->withoutToken()->get("/s/{$token}")->assertStatus(404);

    // Otherwise the owner's screen would report a viewer who saw nothing.
    expect(LiveShare::sole()->view_count)->toBe(0);
});

it('refuses to revoke somebody else share', function () {
    underway($this->driverToken, $this->tripId);
    $id = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.id');

    test()->withToken(unrelatedAccount())->deleteJson("/api/v1/safety/live-shares/{$id}")
        ->assertStatus(404);

    expect(LiveShare::sole()->revoked_at)->toBeNull();
});

it('refuses to revoke the same share twice', function () {
    underway($this->driverToken, $this->tripId);
    $id = shareTrip($this->paxToken, $this->tripId)->assertStatus(201)->json('data.id');

    test()->withToken($this->paxToken)->deleteJson("/api/v1/safety/live-shares/{$id}")->assertOk();

    test()->withToken($this->paxToken)->deleteJson("/api/v1/safety/live-shares/{$id}")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'LIVE_SHARE_ALREADY_ENDED');
});

it('never lists one person shares to another', function () {
    underway($this->driverToken, $this->tripId);
    shareTrip($this->paxToken, $this->tripId)->assertStatus(201);

    expect(test()->withToken(unrelatedAccount())->getJson('/api/v1/safety/live-shares')
        ->assertOk()->json('data'))->toBe([]);
});

it('requires a signed-in account to make a link at all', function () {
    underway($this->driverToken, $this->tripId);

    // The page is public; making one is not.
    test()->withoutToken()->postJson("/api/v1/trips/{$this->tripId}/live-share")->assertStatus(401);
    test()->withoutToken()->getJson('/api/v1/safety/live-shares')->assertStatus(401);
});
