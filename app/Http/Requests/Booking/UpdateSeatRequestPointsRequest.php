<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSeatRequestPointsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'pickup' => ['required_without_all:requestedDaysMask,introMessage', 'required_with:dropoff', 'array'],
            'pickup.suggestionId' => ['nullable', 'string', 'size:26'],
            'pickup.lat' => ['required_without:pickup.suggestionId', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required_without:pickup.suggestionId', 'numeric', 'between:-180,180'],
            'pickup.label' => ['required_without:pickup.suggestionId', 'nullable', 'string', 'max:255'],
            'dropoff' => ['required_with:pickup', 'array'],
            'dropoff.suggestionId' => ['nullable', 'string', 'size:26'],
            'dropoff.lat' => ['required_without:dropoff.suggestionId', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required_without:dropoff.suggestionId', 'numeric', 'between:-180,180'],
            'dropoff.label' => ['required_without:dropoff.suggestionId', 'nullable', 'string', 'max:255'],
            'introMessage' => ['sometimes', 'nullable', 'string', 'max:500'],
            'requestedDaysMask' => ['sometimes', 'integer', 'min:1', 'max:127'],
        ];
    }
}
