<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Did you ask to get off here?" (TRIP-20).
 */
final class AnswerEarlyDropRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // `true`: she asked (full fare). `false`: she did not (she pays nothing, the driver pays her seat).
            'askedToGetOff' => ['required', 'boolean'],
        ];
    }
}
