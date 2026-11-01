<?php

namespace App\Domains\Notification\Support;

use App\Domains\Notification\Contracts\PushSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1 (D-0023: FCM, with the APNs key uploaded into Firebase so the
 * same call reaches iPhones). Chosen with `RAFEEQ_PUSH_DRIVER=fcm`; {@see LogPushSender} stays the
 * default for tests and development.
 *
 * 🔴 **No SDK.** The v1 API is two HTTP calls — a signed JWT exchanged for an OAuth access token,
 * and the send itself — and the service account's RS256 signature is one `openssl_sign()`. A
 * dependency for that would be a large surface for a small job.
 *
 * 🔒 **The credentials are a PATH** (`FIREBASE_CREDENTIALS`), read when a message is sent, never
 * committed and never copied into config cache. The access token is cached for a little under its
 * hour so a burst of pushes does not mint one each.
 *
 * Every message carries `notification` (what the lock screen shows) and `data` (the deep link,
 * flat strings — FCM's rule, already enforced by {@see Notifier}).
 */
final class FcmPushSender implements PushSender
{
    private const string SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const string DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    // What Google allows an assertion to ask for (one hour), and how long the token is cached: a
    // little under that, so a token is never used in its last minutes.
    private const int TOKEN_LIFETIME_SECONDS = 3600;

    private const int TOKEN_TTL_SECONDS = 3300;

    public function __construct(
        private readonly string $credentialsPath,
        private readonly ?string $projectId = null,
    ) {}

    public static function fromConfig(): self
    {
        $path = (string) config('services.fcm.credentials');

        if ($path === '') {
            throw new RuntimeException('RAFEEQ_PUSH_DRIVER=fcm needs FIREBASE_CREDENTIALS (the service-account JSON path).');
        }

        return new self($path, config('services.fcm.project_id') ?: null);
    }

    public function delivers(): bool
    {
        return true;
    }

    public function send(string $pushToken, string $title, string $body, array $data): void
    {
        $account = $this->account();
        $project = $this->projectId ?? $account['project_id'];

        $response = Http::withToken($this->accessToken($account))
            ->acceptJson()
            ->timeout(10)
            ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                'message' => [
                    'token' => $pushToken,
                    'notification' => ['title' => $title, 'body' => $body],
                    // FCM rejects an empty array where it wants a map.
                    'data' => (object) $data,
                    'android' => ['priority' => 'high'],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ],
            ]);

        if ($response->successful()) {
            return;
        }

        $status = (string) ($response->json('error.status') ?? '');
        $fcmCode = collect((array) $response->json('error.details'))
            ->pluck('errorCode')->filter()->first();

        // The token is dead: retrying cannot help, and the device should forget it.
        // Only UNREGISTERED: INVALID_ARGUMENT can just as well mean a bad payload, and forgetting a
        // working token over our own mistake would silence that phone for good.
        if ($fcmCode === 'UNREGISTERED') {
            throw new PushTokenRejected('fcm_unregistered', $response->status());
        }

        // 🔒 Neither the body nor the token in the message: the response can echo the request.
        throw new RuntimeException('fcm_'.strtolower($status !== '' ? $status : 'http_error'), $response->status());
    }

    /**
     * @return array{client_email: string, private_key: string, project_id: string, token_uri?: string}
     */
    private function account(): array
    {
        $raw = is_readable($this->credentialsPath) ? file_get_contents($this->credentialsPath) : false;
        $account = $raw === false ? null : json_decode($raw, true);

        if (! is_array($account) || empty($account['client_email']) || empty($account['private_key'])
            || (empty($account['project_id']) && $this->projectId === null)) {
            throw new RuntimeException('The Firebase service-account file is missing or incomplete.');
        }

        return $account;
    }

    /**
     * @param  array{client_email: string, private_key: string, token_uri?: string}  $account
     */
    private function accessToken(array $account): string
    {
        return Cache::remember('fcm.access_token.'.sha1($account['client_email']), self::TOKEN_TTL_SECONDS,
            function () use ($account): string {
                $tokenUri = $account['token_uri'] ?? self::DEFAULT_TOKEN_URI;

                $response = Http::asForm()->timeout(10)->post($tokenUri, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $this->assertion($account, $tokenUri),
                ]);

                $token = $response->json('access_token');

                if (! $response->successful() || ! is_string($token) || $token === '') {
                    throw new RuntimeException('fcm_auth_failed', $response->status());
                }

                return $token;
            });
    }

    /**
     * The service account's signed request for an access token (RFC 7523, RS256).
     *
     * @param  array{client_email: string, private_key: string}  $account
     */
    private function assertion(array $account, string $tokenUri): string
    {
        $now = time();

        $segments = [
            self::base64Url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            self::base64Url((string) json_encode([
                'iss' => $account['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + self::TOKEN_LIFETIME_SECONDS,
            ])),
        ];

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Firebase service-account key could not sign.');
        }

        $segments[] = self::base64Url($signature);

        return implode('.', $segments);
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
