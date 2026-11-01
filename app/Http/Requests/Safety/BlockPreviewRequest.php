<?php

namespace App\Http\Requests\Safety;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What blocking somebody would do, asked before the confirm button (Feature 03 files X-4).
 *
 * ---
 *
 * Maintainer note: read-only. The answer comes from the same query the block itself runs
 * (`BlockedBookings`), so the preview cannot promise something the block does differently.
 */
final class BlockPreviewRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * The person she is thinking of blocking — the `otherPartyUserId` from the trip
             * summary (`GET /bookings/{booking}` once the trip has ended).
             */
            'userId' => ['required', 'string', 'size:26'],
        ];
    }
}
