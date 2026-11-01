<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Contracts\OtpSender;
use App\Domains\Identity\Enums\OtpPurpose;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The real OTP transport (F1-6): one HTTP POST to an SMS gateway, chosen with
 * `RAFEEQ_OTP_DRIVER=http`.
 *
 * 🔴 **Why configured rather than written per provider.** The provider is still a business choice,
 * and the gateways sold in Egypt differ almost only in what they call three parameters — the number,
 * the text and the sender name — and where the key goes. So those are env vars
 * (`rafeeq.auth.otp.sms`), and signing with a provider is a deploy, not a release. A provider whose
 * API is genuinely different (a signed request, a template id instead of text) gets its own class
 * beside this one and one line in `AppServiceProvider`; nothing above {@see OtpSender} changes.
 *
 * 🔒 The code is in the request body and nowhere else: never in a log line, never in the exception
 * message (a failed send reports the status, not what was sent), never in the URL.
 */
final class HttpSmsOtpSender implements OtpSender
{
    /**
     * @param  array{to: string, message: string, sender: string, api_key: string}  $fields
     */
    public function __construct(
        private readonly string $url,
        private readonly string $apiKey,
        private readonly string $sender,
        private readonly string $auth,
        private readonly array $fields,
        private readonly int $timeoutSeconds = 10,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('rafeeq.auth.otp.sms');

        foreach (['url' => 'RAFEEQ_SMS_URL', 'api_key' => 'RAFEEQ_SMS_API_KEY'] as $key => $env) {
            if ((string) ($config[$key] ?? '') === '') {
                throw new RuntimeException("RAFEEQ_OTP_DRIVER=http needs {$env}.");
            }
        }

        return new self(
            url: (string) $config['url'],
            apiKey: (string) $config['api_key'],
            sender: (string) ($config['sender'] ?? 'Rafeeq'),
            auth: (string) ($config['auth'] ?? 'bearer'),
            fields: $config['fields'],
            timeoutSeconds: (int) ($config['timeout_seconds'] ?? 10),
        );
    }

    public function send(PhoneNumber $phone, string $code, OtpPurpose $purpose): void
    {
        $body = [
            $this->fields['to'] => $phone->e164,
            $this->fields['message'] => __('auth.otp_sms', ['code' => $code], 'ar'),
            $this->fields['sender'] => $this->sender,
        ];

        $request = Http::acceptJson()->timeout($this->timeoutSeconds);

        if ($this->auth === 'field') {
            $body[$this->fields['api_key']] = $this->apiKey;
        } else {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post($this->url, $body);

        // `RequestOtpAction` turns this into AUTH_OTP_DELIVERY_FAILED and withdraws the challenge,
        // so she is never left waiting for a message that was refused.
        if (! $response->successful()) {
            throw new RuntimeException("SMS gateway refused the OTP message (HTTP {$response->status()}).");
        }
    }
}
