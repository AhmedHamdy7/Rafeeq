<?php

namespace App\Http\Requests\Driver;

use App\Domains\Driver\Enums\FuelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Vehicle details (Chapter 3 §7).
 *
 * ---
 *
 * Maintainer notes, kept out of the rules array:
 *
 * - The seat range and the year floor are platform settings, not literals;
 *   the chapter calls the year "configurable" explicitly.
 * - `plateNumber` is stored as typed and normalised separately into
 *   `plate_normalized`, which carries the platform-wide unique index — so
 *   `ABC 123` and `abc123` cannot both be registered.
 * - `transmission` is free text rather than an enum: the ERD column has no
 *   documented value set, and inventing one risks refusing a real car.
 */
final class StoreVehicleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'make' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:50'],

            // Model year. Cars older than the platform minimum are not
            // accepted.
            'year' => [
                'required', 'integer',
                'min:'.config('rafeeq.driver.vehicle_minimum_year'),
                'max:'.(now()->year + 1),
            ],

            'colour' => ['required', 'string', 'max:30'],

            // The plate exactly as it appears. Spaces and letter case do not
            // matter; the same plate cannot be registered twice on Rafeeq.
            'plateNumber' => ['required', 'string', 'max:20'],

            // TOTAL seats including the driver's. Bookable seats are always
            // one fewer.
            'seats' => [
                'required', 'integer',
                'min:'.config('rafeeq.driver.vehicle_minimum_seats'),
                'max:'.config('rafeeq.driver.vehicle_maximum_seats'),
            ],

            'transmission' => ['nullable', 'string', 'max:20'],
            'fuelType' => ['nullable', 'string', Rule::enum(FuelType::class)],
        ];
    }
}
