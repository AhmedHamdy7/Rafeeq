<?php

namespace App\Domains\Trip\ValueObjects;

use App\Domains\Trip\Actions\RecordTripLocationAction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One reported position of a car.
 *
 * A value object rather than an array because these travel through four places — the
 * request, the cache, the broadcast and the bulk insert — and an array would arrive at each
 * of them with slightly different keys.
 *
 * 🔴 `recordedAt` is the DEVICE's clock, and the Bible says so explicitly ("device time, not
 * receive time"). That is the right choice: a phone that loses signal in a tunnel and sends
 * six buffered points on the other side is describing six different moments, and stamping
 * them all with the arrival time would draw a car that teleported. It also means the value
 * is not ours, so nothing may trust it without bounding it first — see
 * {@see RecordTripLocationAction}.
 */
final readonly class TripPosition
{
    public function __construct(
        public float $lat,
        public float $lng,
        /** The device's own clock. Untrusted — bounded by the Action before it gets here. */
        public CarbonInterface $recordedAt,
        /** How confident the phone was, in metres. Null when it did not say. */
        public ?int $accuracyMeters = null,
        public ?int $speedKmh = null,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            lat: (float) $row['lat'],
            lng: (float) $row['lng'],
            recordedAt: CarbonImmutable::parse($row['recordedAt']),
            accuracyMeters: isset($row['accuracyMeters']) ? (int) $row['accuracyMeters'] : null,
            speedKmh: isset($row['speedKmh']) ? (int) $row['speedKmh'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->lat,
            'lng' => $this->lng,
            // ISO 8601 rather than a timestamp: the value crosses a cache and a broadcast,
            // and a bare integer loses the timezone the moment somebody reads it wrong.
            'recordedAt' => $this->recordedAt->toIso8601String(),
            'accuracyMeters' => $this->accuracyMeters,
            'speedKmh' => $this->speedKmh,
        ];
    }
}
