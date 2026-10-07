<?php

namespace App\Domains\Geo\Support;

use App\Domains\Shared\ValueObjects\Coordinate;

/**
 * Google's encoded polyline format, both directions.
 *
 * Needed for more than storage. Pitfall #18: a bounding box computed from only
 * the origin and destination misses any route that bows outward, so an offer
 * whose road curves north of both endpoints would be invisible to a search
 * standing right next to it. Computing the box correctly means walking every
 * point of the line, which means being able to decode it.
 *
 * The algorithm is Google's published one: deltas, zig-zag encoded, five bits
 * at a time, ASCII-shifted by 63.
 */
final class Polyline
{
    private const int PRECISION = 5;

    /**
     * @param  array<int, Coordinate>  $points
     */
    public static function encode(array $points): string
    {
        $encoded = '';
        $previousLat = 0;
        $previousLng = 0;

        foreach ($points as $point) {
            $lat = (int) round($point->lat * 10 ** self::PRECISION);
            $lng = (int) round($point->lng * 10 ** self::PRECISION);

            $encoded .= self::encodeValue($lat - $previousLat);
            $encoded .= self::encodeValue($lng - $previousLng);

            $previousLat = $lat;
            $previousLng = $lng;
        }

        return $encoded;
    }

    /**
     * Whether a stored polyline decodes to real coordinates.
     *
     * A route is written once, at publish, and read on every search — so a malformed one (bad
     * data, a hand-edited row) would otherwise fail every request that touches it. Readers that
     * should skip a broken commute rather than fail the whole request ask this first.
     */
    public static function isReadable(string $encoded): bool
    {
        if ($encoded === '') {
            return false;
        }

        try {
            return count(self::decode($encoded)) >= 2;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<int, Coordinate>
     */
    public static function decode(string $encoded): array
    {
        $points = [];
        $index = 0;
        $length = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            $lat += self::decodeValue($encoded, $index);
            $lng += self::decodeValue($encoded, $index);

            $points[] = new Coordinate(
                $lat / 10 ** self::PRECISION,
                $lng / 10 ** self::PRECISION,
            );
        }

        return $points;
    }

    private static function encodeValue(int $value): string
    {
        // Zig-zag: shift left one bit, and invert every bit of a negative so
        // small negatives stay small numbers rather than becoming huge ones.
        $value = $value < 0 ? ~($value << 1) : $value << 1;

        $chunk = '';

        while ($value >= 0x20) {
            $chunk .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $chunk.chr($value + 63);
    }

    private static function decodeValue(string $encoded, int &$index): int
    {
        $shift = 0;
        $result = 0;

        do {
            $byte = ord($encoded[$index++]) - 63;
            $result |= ($byte & 0x1F) << $shift;
            $shift += 5;
        } while ($byte >= 0x20 && $index < strlen($encoded));

        return ($result & 1) !== 0 ? ~($result >> 1) : $result >> 1;
    }
}
