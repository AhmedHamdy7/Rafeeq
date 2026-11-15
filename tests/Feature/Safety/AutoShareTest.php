<?php

use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Models\Notification;
use App\Domains\Safety\Contracts\ContactMessenger;
use App\Domains\Safety\Jobs\SendAutoShareLinks;
use App\Domains\Safety\Models\AutoShareMessage;
use App\Domains\Safety\Models\LiveShare;
use App\Domains\Trip\Models\TripSession;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    config(['rafeeq.testing.api' => true, 'rafeeq.safety.auto_share_driver' => 'log']);

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

function addTrustedContact(string $token, string $name, string $phone): string
{
    return test()->withToken($token)->postJson('/api/v1/safety/emergency-contacts', [
        'name' => $name,
        'phone' => $phone,
    ])->assertStatus(201)->json('data.id');
}

function turnAutoShareOffForEveryone(): void
{
    PlatformSetting::query()->create([
        'setting_key' => 'safety.auto_share_enabled',
        'setting_value' => 0,
        'value_type' => 'integer',
        'description' => 'Off',
    ]);
}

function switchAutoShare(string $token, bool $enabled)
{
    return test()->withToken($token)->putJson('/api/v1/safety/auto-share', ['enabled' => $enabled]);
}

it('reads and writes the account switch and says whether the feature is live', function () {
    test()->withToken($this->paxToken)->getJson('/api/v1/safety/emergency-contacts')
        ->assertOk()
        ->assertJsonPath('meta.autoShare', false)
        ->assertJsonPath('meta.autoShareAvailable', true);

    switchAutoShare($this->paxToken, true)
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.available', true);

    test()->withToken($this->paxToken)->getJson('/api/v1/safety/emergency-contacts')
        ->assertJsonPath('meta.autoShare', true);

    switchAutoShare($this->paxToken, false)->assertJsonPath('data.enabled', false);
});

it('refuses a switch without a boolean', function () {
    test()->withToken($this->paxToken)->putJson('/api/v1/safety/auto-share', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('says the feature is not live when staff turn it off', function () {
    turnAutoShareOffForEveryone();

    test()->withToken($this->paxToken)->getJson('/api/v1/safety/emergency-contacts')
        ->assertJsonPath('meta.autoShareAvailable', false);
});

it('says the feature is not live when no SMS gateway is configured', function () {
    config(['rafeeq.safety.auto_share_driver' => 'http', 'rafeeq.auth.otp.sms.url' => null]);

    test()->withToken($this->paxToken)->getJson('/api/v1/safety/emergency-contacts')
        ->assertJsonPath('meta.autoShareAvailable', false);
});

it('sends each trusted contact one link when the run leaves', function () {
    $hoda = addTrustedContact($this->paxToken, 'Hoda', '01223334444');
    $nada = addTrustedContact($this->paxToken, 'Nada', '01005556666');
    switchAutoShare($this->paxToken, true)->assertOk();

    underway($this->driverToken, $this->tripId);

    $messages = test()->withToken($this->paxToken)->getJson('/api/v1/testing/safety/auto-share/messages')
        ->assertOk()
        ->assertJsonPath('meta.tripId', $this->tripId)
        ->json('data');

    expect($messages)->toHaveCount(2)
        ->and(array_column($messages, 'contactId'))->toEqualCanonicalizing([$hoda, $nada])
        ->and(array_column($messages, 'status'))->each->toBe('SENT')
        ->and(array_column($messages, 'channel'))->each->toBe('SMS')
        ->and($messages[0]['url'])->toContain('/s/');

    $this->get(parse_url($messages[0]['url'], PHP_URL_PATH))->assertOk();

    $shares = test()->withToken($this->paxToken)->getJson('/api/v1/safety/live-shares')->assertOk()->json('data');

    expect($shares)->toHaveCount(2)
        ->and(array_column($shares, 'source'))->each->toBe('AUTO')
        ->and(array_column($shares, 'isActive'))->each->toBeTrue();

    $notice = Notification::query()->where('type', 'live_trip_auto_shared')->where('channel', 'in_app')->sole();

    expect($notice->data['tripId'])->toBe($this->tripId)
        ->and(explode(',', $notice->data['sentTo']))->toEqualCanonicalizing([$hoda, $nada]);
});

it('shares the driver’s own run when she has it on', function () {
    addTrustedContact($this->driverToken, 'Mona', '01223334444');
    switchAutoShare($this->driverToken, true)->assertOk();

    underway($this->driverToken, $this->tripId);

    test()->withToken($this->driverToken)->getJson('/api/v1/testing/safety/auto-share/messages')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('sends nothing when the switch is off', function () {
    addTrustedContact($this->paxToken, 'Hoda', '01223334444');

    underway($this->driverToken, $this->tripId);

    expect(AutoShareMessage::query()->count())->toBe(0)
        ->and(LiveShare::query()->count())->toBe(0);
});

it('sends nothing when the feature is not live', function () {
    addTrustedContact($this->paxToken, 'Hoda', '01223334444');
    switchAutoShare($this->paxToken, true)->assertOk();
    turnAutoShareOffForEveryone();

    underway($this->driverToken, $this->tripId);

    expect(AutoShareMessage::query()->count())->toBe(0);
});

it('sends once per trip, however often the run is looked at', function () {
    addTrustedContact($this->paxToken, 'Hoda', '01223334444');
    switchAutoShare($this->paxToken, true)->assertOk();

    underway($this->driverToken, $this->tripId);

    SendAutoShareLinks::dispatchSync(TripSession::query()->sole()->id);

    expect(AutoShareMessage::query()->count())->toBe(1);
});

it('lets her stop an automatic link', function () {
    addTrustedContact($this->paxToken, 'Hoda', '01223334444');
    switchAutoShare($this->paxToken, true)->assertOk();

    underway($this->driverToken, $this->tripId);

    $shareId = test()->withToken($this->paxToken)->getJson('/api/v1/safety/live-shares')->json('data.0.id');

    test()->withToken($this->paxToken)->deleteJson("/api/v1/safety/live-shares/{$shareId}")
        ->assertOk()
        ->assertJsonPath('data.isActive', false);
});

it('ends a contact’s link when she removes the contact', function () {
    $hoda = addTrustedContact($this->paxToken, 'Hoda', '01223334444');
    switchAutoShare($this->paxToken, true)->assertOk();

    underway($this->driverToken, $this->tripId);

    test()->withToken($this->paxToken)->deleteJson("/api/v1/safety/emergency-contacts/{$hoda}")->assertOk();

    expect(LiveShare::query()->sole()->revoked_at)->not->toBeNull();
});

it('ends a link nobody received and tells her it was not shared', function () {
    $this->app->bind(ContactMessenger::class, fn () => new class implements ContactMessenger
    {
        public function channel(): string
        {
            return 'sms';
        }

        public function keepsPreview(): bool
        {
            return false;
        }

        public function send(string $e164, string $text): void
        {
            throw new RuntimeException('down');
        }
    });

    addTrustedContact($this->paxToken, 'Hoda', '01223334444');
    switchAutoShare($this->paxToken, true)->assertOk();

    underway($this->driverToken, $this->tripId);

    test()->withToken($this->paxToken)->getJson('/api/v1/testing/safety/auto-share/messages')
        ->assertJsonPath('data.0.status', 'FAILED')
        ->assertJsonPath('data.0.failureReason', 'DELIVERY_FAILED')
        ->assertJsonPath('data.0.url', null);

    expect(LiveShare::query()->sole()->revoked_at)->not->toBeNull()
        ->and(Notification::query()->where('type', 'live_trip_auto_share_failed')->exists())->toBeTrue();
});

it('writes the message in her app language', function () {
    $sent = [];

    $this->app->bind(ContactMessenger::class, function () use (&$sent) {
        return new class($sent) implements ContactMessenger
        {
            public function __construct(private array &$sent) {}

            public function channel(): string
            {
                return 'sms';
            }

            public function keepsPreview(): bool
            {
                return false;
            }

            public function send(string $e164, string $text): void
            {
                $this->sent[] = $text;
            }
        };
    });

    User::query()->where('phone_e164', '+201112223344')->update(['preferred_language' => 'ar']);
    addTrustedContact($this->paxToken, 'Hoda', '01223334444');
    switchAutoShare($this->paxToken, true)->assertOk();

    underway($this->driverToken, $this->tripId);

    expect($sent)->toHaveCount(1)
        ->and($sent[0])->toContain('رفيق')
        ->and($sent[0])->toContain('/s/');
});
