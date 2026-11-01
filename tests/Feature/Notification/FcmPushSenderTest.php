<?php

use App\Domains\Identity\Models\Device;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Contracts\PushSender;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Notification;
use App\Domains\Notification\Support\FcmPushSender;
use App\Domains\Notification\Support\LogPushSender;
use App\Domains\Notification\Support\Notifier;
use App\Domains\Notification\Support\PushTokenRejected;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * FCM HTTP v1 (D-0023, Feature 03 files §8 / N-1).
 *
 * No network: every call is faked. What is pinned here is the contract with Google — a signed
 * service-account assertion swapped for a bearer token, one POST per message to the project's
 * `messages:send` — and what happens when FCM says a token is dead.
 */
beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);

    $this->credentials = tempnam(sys_get_temp_dir(), 'fcm');
    file_put_contents($this->credentials, json_encode([
        'type' => 'service_account',
        'project_id' => 'rafeeq-test',
        'client_email' => 'push@rafeeq-test.iam.gserviceaccount.com',
        'private_key' => $pem,
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));

    $this->publicKey = openssl_pkey_get_details($key)['key'];
});

afterEach(function () {
    @unlink($this->credentials);
});

it('stays on the log transport unless a server chooses FCM', function () {
    expect(app(PushSender::class))->toBeInstanceOf(LogPushSender::class);

    config([
        'rafeeq.notifications.push_driver' => 'fcm',
        'services.fcm.credentials' => $this->credentials,
    ]);

    expect(app(PushSender::class))->toBeInstanceOf(FcmPushSender::class)
        ->and(app(PushSender::class)->delivers())->toBeTrue();
});

it('signs in as the service account and sends one message to the project', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3600]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/rafeeq-test/messages/1']),
    ]);

    $sender = new FcmPushSender($this->credentials);

    $sender->send('device-token', 'Omar is on the way to you', 'You are the next pickup.', ['bookingId' => '01J']);
    $sender->send('device-token', 'Again', 'Body', []);

    $recorded = Http::recorded();

    // One token for both messages: it is cached, not minted per push.
    $tokenCalls = $recorded->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2'));
    expect($tokenCalls)->toHaveCount(1);

    /** @var Request $auth */
    $auth = $tokenCalls->first()[0];
    [$header, $claims, $signature] = explode('.', $auth['assertion']);
    $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));
    $payload = json_decode($decode($claims), true);

    expect($auth['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer')
        ->and($payload['iss'])->toBe('push@rafeeq-test.iam.gserviceaccount.com')
        ->and($payload['scope'])->toBe('https://www.googleapis.com/auth/firebase.messaging')
        ->and(openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256))->toBe(1);

    // Read from the wire, as FCM reads it: the sender sends `data` as a JSON object.
    Http::assertSent(function (Request $request): bool {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://fcm.googleapis.com/v1/projects/rafeeq-test/messages:send'
            && $request->hasHeader('Authorization', 'Bearer ya29.token')
            && $body['message']['token'] === 'device-token'
            && $body['message']['notification']['title'] === 'Omar is on the way to you'
            && $body['message']['data']['bookingId'] === '01J';
    });
});

/**
 * 🔴 A dead token is forgotten, not retried: the app was uninstalled or the token rotated, and every
 * later message would fail the same way.
 */
it('forgets a token FCM says is no longer registered', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.token']),
        'fcm.googleapis.com/*' => Http::response(['error' => [
            'code' => 404,
            'status' => 'NOT_FOUND',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']],
        ]], 404),
    ]);

    config([
        'rafeeq.notifications.push_driver' => 'fcm',
        'services.fcm.credentials' => $this->credentials,
    ]);

    $user = User::factory()->create();
    $device = Device::factory()->for($user)->create(['push_token' => 'dead-token']);

    Notifier::send($user, NotificationType::DriverArrived, params: ['name' => 'Omar']);

    $push = Notification::query()->where('user_id', $user->id)->where('channel', 'push')->sole();

    expect($push->failed_reason)->toBe('token_rejected')
        ->and($push->sent_at)->toBeNull()
        ->and($device->refresh()->push_token)->toBeNull();
});

it('reports any other refusal without the token in it', function () {
    Http::fake([
        'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.token']),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
    ]);

    try {
        (new FcmPushSender($this->credentials))->send('secret-device-token', 't', 'b', []);
        $thrown = null;
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    // Retryable, so not a rejected token — and the message carries the status, never the token.
    expect($thrown)->not->toBeNull()
        ->and($thrown)->not->toBeInstanceOf(PushTokenRejected::class)
        ->and($thrown->getMessage())->toBe('fcm_unavailable')
        ->and($thrown->getCode())->toBe(503);
});
