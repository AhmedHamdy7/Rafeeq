<?php

namespace App\Http\Requests\Matching;

use App\Domains\Driver\Enums\VehicleTier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class BrowseCommutesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'womenOnly' => ['nullable', 'in:true,false,1,0'],
            'tier' => ['nullable', 'string', Rule::enum(VehicleTier::class)],
            'daysMask' => ['nullable', 'integer', 'min:1', 'max:127'],
        ];
    }
}
