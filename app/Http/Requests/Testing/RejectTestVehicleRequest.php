<?php

namespace App\Http\Requests\Testing;

use App\Domains\Driver\Enums\VehicleRejectedItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RejectTestVehicleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'item' => ['nullable', 'string', Rule::enum(VehicleRejectedItem::class)],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
