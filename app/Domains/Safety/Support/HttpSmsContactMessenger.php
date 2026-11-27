<?php

namespace App\Domains\Safety\Support;

use App\Domains\Safety\Contracts\ContactMessenger;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class HttpSmsContactMessenger implements ContactMessenger
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

    public static function configured(): bool
    {
        $config = (array) config('rafeeq.auth.otp.sms');

        return (string) ($config['url'] ?? '') !== '' && (string) ($config['api_key'] ?? '') !== '';
    }

    public static function fromConfig(): self
    {
        if (! self::configured()) {
            throw new RuntimeException('RAFEEQ_AUTO_SHARE_DRIVER=http needs RAFEEQ_SMS_URL and RAFEEQ_SMS_API_KEY.');
        }

        $config = (array) config('rafeeq.auth.otp.sms');

        return new self(
            url: (string) $config['url'],
            apiKey: (string) $config['api_key'],
            sender: (string) ($config['sender'] ?? 'YuGo'),
            auth: (string) ($config['auth'] ?? 'bearer'),
            fields: $config['fields'],
            timeoutSeconds: (int) ($config['timeout_seconds'] ?? 10),
        );
    }

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
        $body = [
            $this->fields['to'] => $e164,
            $this->fields['message'] => $text,
            $this->fields['sender'] => $this->sender,
        ];

        $request = Http::acceptJson()->timeout($this->timeoutSeconds);

        if ($this->auth === 'field') {
            $body[$this->fields['api_key']] = $this->apiKey;
        } else {
            $request = $request->withToken($this->apiKey);
        }

        $response = $request->post($this->url, $body);

        if (! $response->successful()) {
            throw new RuntimeException("SMS gateway refused the message (HTTP {$response->status()}).");
        }
    }
}
