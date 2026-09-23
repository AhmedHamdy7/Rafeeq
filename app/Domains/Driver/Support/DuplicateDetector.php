<?php

namespace App\Domains\Driver\Support;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;

/**
 * Chapter 3 §14: detect duplicate national IDs, licence numbers and plates.
 *
 * Every lookup here is on a HASH, never on a decrypted value. The national id
 * and licence number are stored encrypted precisely so nobody has to hold them
 * in the clear, and a duplicate check that decrypted every row to compare would
 * throw that away — while also being a table scan. sha256 makes it an indexed
 * equality match against a column that leaks nothing if the database is read.
 *
 * What the caller may do with the answer is narrow on purpose. A duplicate is
 * reported as "something here is already registered" and never as which field
 * matched: an applicant who already knows their own details learns nothing
 * from the distinction, while someone probing learns whether a specific
 * person or car is on the platform.
 */
final class DuplicateDetector
{
    public static function nationalIdTaken(string $nationalId, ?string $exceptUserId = null): bool
    {
        return DriverProfile::query()
            ->where('national_id_hash', hash('sha256', $nationalId))
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists();
    }

    public static function licenceNumberTaken(string $licenceNumber, ?string $exceptUserId = null): bool
    {
        return DriverProfile::query()
            ->where('licence_number_hash', hash('sha256', $licenceNumber))
            ->when($exceptUserId !== null, fn ($query) => $query->whereKeyNot($exceptUserId))
            ->exists();
    }

    /**
     * Compared on the normalised form, so `ABC 123` and `abc123` are the same
     * plate — the column is unique on that form for the same reason.
     */
    public static function plateTaken(string $plateNumber, ?string $exceptVehicleId = null): bool
    {
        return Vehicle::query()
            ->where('plate_normalized', Vehicle::normalizePlate($plateNumber))
            ->when($exceptVehicleId !== null, fn ($query) => $query->whereKeyNot($exceptVehicleId))
            ->exists();
    }
}
