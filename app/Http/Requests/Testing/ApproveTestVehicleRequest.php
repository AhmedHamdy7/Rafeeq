<?php

namespace App\Http\Requests\Testing;

use App\Domains\Driver\Enums\VehicleTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApproveTestVehicleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'approvedSeats' => ['nullable', 'integer'],
            'tier' => ['nullable', 'string', Rule::enum(VehicleTier::class)],
            'hasAc' => ['nullable', 'boolean'],
        ];
    }
}
