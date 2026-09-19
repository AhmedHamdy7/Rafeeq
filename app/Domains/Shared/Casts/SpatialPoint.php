<?php

namespace App\Domains\Shared\Casts;

use App\Domains\Shared\ValueObjects\Coordinate;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a MySQL/MariaDB POINT column to/from {@see Coordinate}.
 *
 * Both directions speak the server's "internal" geometry format: a 4-byte
 * little-endian SRID followed by standard WKB. Writing that blob as an
 * ordinary bound parameter (rather than emitting `ST_GeomFromText(...)` as a
 * query Expression) is what keeps reads consistent: Eloquent only caches a
 * cast value when the input was an object, so an Expression left
 * `$model->point` returning null after `create(['point' => ['lat' => …]])`
 * until the model was refreshed. With a plain string on the way in, `get()`
 * has exactly one code path and both input forms behave identically.
 *
 * @implements CastsAttributes<Coordinate, Coordinate|array{lat: float, lng: float}>
 */
final readonly class SpatialPoint implements CastsAttributes
{
    private const int WKB_POINT = 1;

    private const int LITTLE_ENDIAN = 1;

    public function __construct(private int $srid = 4326) {}

    public function get(Model $model, string $key, mixed $value, array $attributes): ?Coordinate
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $this->fromInternalWkb($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->toInternalWkb($this->toCoordinate($value));
    }

    private function toCoordinate(mixed $value): Coordinate
    {
        if ($value instanceof Coordinate) {
            return $value;
        }

        if (is_array($value) && isset($value['lat'], $value['lng'])) {
            return new Coordinate(lat: (float) $value['lat'], lng: (float) $value['lng']);
        }

        throw new InvalidArgumentException(
            'A spatial point must be a Coordinate or an array with lat and lng keys.'
        );
    }

    private function toInternalWkb(Coordinate $coordinate): string
    {
        return pack('V', $this->srid)
            .pack('C', self::LITTLE_ENDIAN)
            .pack('V', self::WKB_POINT)
            .pack('e', $coordinate->lng)  // WKB stores X (longitude) first
            .pack('e', $coordinate->lat);
    }

    private function fromInternalWkb(string $binary): ?Coordinate
    {
        // 4-byte SRID header + WKB(1-byte order, 4-byte type, 8-byte x, 8-byte y).
        if (strlen($binary) < 25) {
            return null;
        }

        $littleEndian = ord($binary[4]) === self::LITTLE_ENDIAN;

        $type = unpack($littleEndian ? 'V' : 'N', substr($binary, 5, 4))[1];

        if ($type !== self::WKB_POINT) {
            return null;
        }

        $x = unpack($littleEndian ? 'e' : 'E', substr($binary, 9, 8))[1];
        $y = unpack($littleEndian ? 'e' : 'E', substr($binary, 17, 8))[1];

        return new Coordinate(lat: $y, lng: $x);
    }
}
