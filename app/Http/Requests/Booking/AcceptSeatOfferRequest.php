<?php

namespace App\Http\Requests\Booking;

use App\Domains\Booking\Enums\SwitchReason;
use App\Domains\Booking\Enums\SwitchScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Taking a seat she was offered — optionally giving up another one in the same breath.
 *
 * ---
 *
 * Maintainer notes (comments above the rules are published as field descriptions in the OpenAPI
 * document, so they are written for the mobile team — binding standard #38):
 *
 * Every field is optional, so a plain acceptance sends an empty body and nothing about switching
 * has to be understood to take a seat.
 */
final class AcceptSeatOfferRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * The confirmed booking you are leaving, if this is a switch. Both happen in one
             * transaction, so you never hold two seats on the same day and never lose both.
             *
             * Leave it out to simply take the seat.
             */
            'switchFromBookingId' => ['sometimes', 'string', 'size:26'],

            /*
             * Why you are leaving: `voluntary` or `driver_delay`.
             *
             * `voluntary` applies the normal cancellation policy to the seat you give up.
             * `driver_delay` carries **no cancellation fee**, and records the old booking as
             * cancelled by the driver — so a driver running late does not land on your record.
             * Ignored unless `switchFromBookingId` is sent.
             */
            'switchReason' => ['sometimes', 'string', Rule::enum(SwitchReason::class)],

            /*
             * How much of the old commute you are leaving: `this_date` (the default) or
             * `all_future_weekdays`.
             *
             * 🔴 `all_future_weekdays` releases **every future day you still hold on the old
             * commute**, and you get **one** seat in return — the day this offer is for. Nothing
             * replaces the rest, and nothing gives them back: the seats are offered to whoever is
             * waiting. **Warn clearly before sending this**, and read `meta.releasedDates` in the
             * response for exactly which mornings went.
             *
             * Ignored unless `switchFromBookingId` is sent.
             */
            'switchScope' => ['sometimes', 'string', Rule::enum(SwitchScope::class)],

            /*
             * Send `true` to rejoin the old driver's waiting list. She does not have to approve you
             * again. It reserves nothing — you wait for an offer like everybody else, and the seat
             * may well go to somebody who was quicker.
             *
             * It is scoped to match what you gave up: for `this_date` you rejoin for that one
             * morning, for `all_future_weekdays` you rejoin for the whole pattern.
             */
            'joinOldWaitlist' => ['sometimes', 'boolean'],
        ];
    }
}
