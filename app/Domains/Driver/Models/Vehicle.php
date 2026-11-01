<?php

namespace App\Domains\Driver\Models;

use App\Domains\Driver\Enums\FuelType;
use App\Domains\Driver\Enums\VehicleTier;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'driver_profile_id', 'make', 'model', 'year', 'colour', 'plate_number',
    'transmission', 'fuel_type', 'photo_path', 'seats', 'is_active',
])]
class Vehicle extends Model
{
    /** @use HasFactory<VehicleFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'seats' => 'integer',
            /*
             * 🔒 The admin's car review (F2-6, PROF-26, PROF-28), deliberately NOT fillable: the
             * driver's vehicle form must never reach them. `approved_seats` limits every commute on
             * this car and divides its seat price; `tier` picks the km rate. Null approved seats
             * means the car has not been reviewed, and it cannot carry a published commute.
             */
            'approved_seats' => 'integer',
            'tier' => VehicleTier::class,
            'has_ac' => 'boolean',
            'is_active' => 'boolean',
            'fuel_type' => FuelType::class,
            'verification_status' => VehicleVerificationStatus::class,
        ];
    }

    /**
     * Plate numbers are stored as typed (with spaces/Arabic formatting) and
     * normalized separately for platform-wide uniqueness — see the
     * migration's `plate_normalized` column.
     */
    protected static function booted(): void
    {
        static::saving(function (self $vehicle): void {
            if ($vehicle->isDirty('plate_number')) {
                $vehicle->plate_normalized = static::normalizePlate($vehicle->plate_number);
            }
        });
    }

    public static function normalizePlate(string $plate): string
    {
        return str_replace(' ', '', mb_strtoupper($plate));
    }

    public function driverProfile(): BelongsTo
    {
        return $this->belongsTo(DriverProfile::class, 'driver_profile_id', 'user_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    /**
     * The tier, defaulting to Standard on an instance that has been created but not reloaded — the
     * column's default lives in the database, not in the model.
     */
    public function tierOrDefault(): VehicleTier
    {
        return $this->tier ?? VehicleTier::Standard;
    }

    public function isApproved(): bool
    {
        return $this->verification_status === VehicleVerificationStatus::Approved;
    }
}
