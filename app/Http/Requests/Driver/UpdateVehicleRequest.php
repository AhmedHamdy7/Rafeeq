<?php

namespace App\Http\Requests\Driver;

use App\Domains\Driver\Enums\FuelType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing a vehicle already on the application.
 *
 * ---
 *
 * Maintainer note: every field is `sometimes`, so a partial edit does not wipe
 * what it omits. `verification_status` and `is_active` are absent entirely — a
 * vehicle must never be able to declare itself approved, and activation is its
 * own endpoint because switching the active vehicle has to be atomic.
 */
final class UpdateVehicleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'make' => ['sometimes', 'string', 'max:50'],
            'model' => ['sometimes', 'string', 'max:50'],

            // Model year. Cars older than the platform minimum are not
            // accepted.
            'year' => [
                'sometimes', 'integer',
                'min:'.config('rafeeq.driver.vehicle_minimum_year'),
                'max:'.(now()->year + 1),
            ],

            'colour' => ['sometimes', 'string', 'max:30'],

            // The plate exactly as it appears. Spaces and letter case do not
            // matter; the same plate cannot be registered twice on Rafeeq.
            'plateNumber' => ['sometimes', 'string', 'max:20'],

            // TOTAL seats including the driver's. Bookable seats are one fewer.
            'seats' => [
                'sometimes', 'integer',
                'min:'.config('rafeeq.driver.vehicle_minimum_seats'),
                'max:'.config('rafeeq.driver.vehicle_maximum_seats'),
            ],

            'transmission' => ['sometimes', 'nullable', 'string', 'max:20'],
            'fuelType' => ['sometimes', 'nullable', 'string', Rule::enum(FuelType::class)],
        ];
    }
}
