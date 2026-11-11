<?php

namespace App\Http\Requests\Testing;

use Illuminate\Foundation\Http\FormRequest;

final class PlaceCarRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'beforeBookingId' => ['required_without:lat', 'nullable', 'string', 'size:26'],
            'lat' => ['required_without:beforeBookingId', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_with:lat', 'nullable', 'numeric', 'between:-180,180'],
            'metres' => ['nullable', 'integer', 'between:0,50000'],
        ];
    }
}
