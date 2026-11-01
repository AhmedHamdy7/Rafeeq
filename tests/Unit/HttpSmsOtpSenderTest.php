<?php

use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Identity\Support\HttpSmsOtpSender;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F1-6: the SMS gateway transport. The provider is not chosen yet, so what is tested is the part
 * that does not depend on it — the field mapping, where the key goes, and that a refusal is loud.
 */
uses(TestCase::class);

function smsSender(string $auth = 'bearer'): HttpSmsOtpSender
{
    return new HttpSmsOtpSender(
        url: 'https://sms.example.test/send',
        apiKey: 'test-key',
        sender: 'Rafeeq',
        auth: $auth,
        fields: ['to' => 'mobile', 'message' => 'text', 'sender' => 'from', 'api_key' => 'key'],
    );
}

it('posts the number, the text and the sender under the provider field names', function () {
    Http::fake(['sms.example.test/*' => Http::response(['ok' => true])]);

    smsSender()->send(PhoneNumber::fromRaw('01012345678'), '123456', OtpPurpose::Authentication);

    Http::assertSent(fn (Request $request) => $request['mobile'] === '+201012345678'
        && str_contains($request['text'], '123456')
        && $request['from'] === 'Rafeeq'
        && $request->hasHeader('Authorization', 'Bearer test-key')
        && ! isset($request['key']));
});

it('can send the key in the body instead of a header', function () {
    Http::fake(['sms.example.test/*' => Http::response(['ok' => true])]);

    smsSender('field')->send(PhoneNumber::fromRaw('01012345678'), '123456', OtpPurpose::Authentication);

    Http::assertSent(fn (Request $request) => $request['key'] === 'test-key' && ! $request->hasHeader('Authorization'));
});

it('throws when the gateway refuses, without the code in the message', function () {
    Http::fake(['sms.example.test/*' => Http::response(['error' => 'bad sender'], 400)]);

    expect(fn () => smsSender()->send(PhoneNumber::fromRaw('01012345678'), '987654', OtpPurpose::Authentication))
        ->toThrow(fn (RuntimeException $e) => expect($e->getMessage())->toContain('400')->not->toContain('987654'));
});

it('refuses to be built without a URL and a key', function () {
    config(['rafeeq.auth.otp.sms.url' => null]);

    expect(fn () => HttpSmsOtpSender::fromConfig())->toThrow(RuntimeException::class, 'RAFEEQ_SMS_URL');
});
