<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

final class SuggestAlternativePickupRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The point you would rather stop at, as the app's place search or pin gives it.
            'lat' => ['required_without:placeId', 'nullable', 'numeric', 'between:-90,90'],
            'lng' => ['required_without:placeId', 'nullable', 'numeric', 'between:-180,180'],
            'label' => ['nullable', 'string', 'max:255'],
            'googlePlaceId' => ['nullable', 'string', 'max:255'],
            'area' => ['nullable', 'array'],
            'area.placeId' => ['nullable', 'string', 'max:255'],
            'area.name' => ['nullable', 'string', 'max:150'],
            'area.city' => ['nullable', 'string', 'max:150'],

            // Older builds: a place from the shared catalogue.
            'placeId' => ['required_without:lat', 'nullable', 'string', 'size:26', 'exists:places,id'],
        ];
    }
}
