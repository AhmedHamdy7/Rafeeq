<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

final class DropoffPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup' => ['nullable', 'array'],
            'pickup.lat' => ['required_with:pickup', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required_with:pickup', 'numeric', 'between:-180,180'],
            'scheduledTripId' => ['nullable', 'string', 'max:26'],
        ];
    }
}
