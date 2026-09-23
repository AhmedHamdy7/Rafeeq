<?php

namespace App\Domains\Identity\Support;

use App\Domains\Identity\Enums\DevicePlatform;

/**
 * The device facts the app sends with an authentication call
 * (Chapter 2 §23.1). `publicId` is generated and stored by the app itself —
 * never an advertising identifier or a hardware serial, so uninstalling
 * genuinely forgets the installation (scenario B) and nothing here can be
 * used to track a person across apps.
 */
final readonly class DeviceIdentity
{
    public function __construct(
        public string $publicId,
        public DevicePlatform $platform,
        public ?string $model = null,
        public ?string $osVersion = null,
        public ?string $appVersion = null,
        public ?string $pushToken = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            publicId: (string) $payload['publicId'],
            platform: DevicePlatform::from((string) $payload['platform']),
            model: $payload['model'] ?? null,
            osVersion: $payload['osVersion'] ?? null,
            appVersion: $payload['appVersion'] ?? null,
            pushToken: $payload['pushToken'] ?? null,
        );
    }
}
