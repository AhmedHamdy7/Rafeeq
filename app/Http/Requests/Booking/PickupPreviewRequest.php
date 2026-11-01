<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking what a meeting point would cost, before anything is created (screen 13, B04).
 *
 * The pickup-request body plus the day she is looking at, so the reply can say when the car would
 * reach that point (DISCOVER-14) — kept apart from {@see RequestPickupPointRequest} because a
 * pickup request on an existing booking already has its day.
 */
final class PickupPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            ...(new RequestPickupPointRequest)->rules(),

            /*
             * The day she is requesting — a match result's `tripId`, or an `otherDays[].id`. Only
             * changes `estimatedPickupAt`; leave it out and the commute's next bookable day is used.
             */
            'scheduledTripId' => ['nullable', 'string', 'max:26'],
        ];
    }
}
