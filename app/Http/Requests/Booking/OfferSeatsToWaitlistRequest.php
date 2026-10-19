<?php

namespace App\Http\Requests\Booking;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which waiting people the driver is offering seats to (Feature 03 v5).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - 🔴 `all` and `seatRequestIds` are mutually exclusive, and neither defaults. v5 lets the driver
 *   offer to "individual, selected subset, or all waiting-list members", and the difference between
 *   "these two" and "everybody" is a decision about her own car — so an empty body must not quietly
 *   mean one of them. A request that says nothing is refused.
 * - The ids are validated as existing seat requests, but WHOSE they are is settled by the
 *   controller against the caller's own commute. `exists` would otherwise let a driver discover
 *   that an id belongs to somebody by the shape of the error.
 */
final class OfferSeatsToWaitlistRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Offer to everybody currently waiting on this commute. Mutually exclusive with
            // seatRequestIds.
            'all' => ['sometimes', 'boolean'],

            // The specific waiting members to offer a seat to, by seat request id.
            'seatRequestIds' => ['sometimes', 'array', 'min:1', 'max:100'],
            'seatRequestIds.*' => ['required', 'string', 'size:26', 'distinct', 'exists:seat_requests,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $all = $this->boolean('all');
            $chosen = $this->input('seatRequestIds');

            if ($all && is_array($chosen) && $chosen !== []) {
                $validator->errors()->add('seatRequestIds', __('validation.custom.waitlist_offer.exclusive'));

                return;
            }

            if (! $all && (! is_array($chosen) || $chosen === [])) {
                $validator->errors()->add('seatRequestIds', __('validation.custom.waitlist_offer.required'));
            }
        });
    }
}
