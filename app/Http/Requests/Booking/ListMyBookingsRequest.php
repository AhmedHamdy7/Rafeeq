<?php

namespace App\Http\Requests\Booking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Which of her bookings `GET /my-bookings` returns (HISTORY-2).
 *
 * ---
 *
 * Maintainer notes:
 *
 * The filter is the server's because paging is. Filtered on the phone, a page of twenty mixed
 * bookings could hold two finished ones, so the History tab read up to five pages to fill a screen
 * and `meta.hasMore` described a list it was not showing.
 */
final class ListMyBookingsRequest extends FormRequest
{
    public const string PAST = 'past';

    public const string UPCOMING = 'upcoming';

    public function rules(): array
    {
        return [
            /*
             * `past`: only finished bookings (`COMPLETED`, `CANCELLED_BY_PASSENGER`,
             * `CANCELLED_BY_DRIVER`, `NO_SHOW`, `EXPIRED`, `ENDED_EARLY`,
             * `EXITED_DUE_TO_DRIVER_DELAY`), newest departure first. `upcoming`: only `PENDING` and
             * `CONFIRMED` seats on journeys that have not finished, soonest departure first. Left out:
             * every booking, newest first, as before. Paging and `meta.hasMore` apply to the
             * filtered list.
             */
            'scope' => ['nullable', 'string', Rule::in([self::PAST, self::UPCOMING])],
        ];
    }

    public function scope(): ?string
    {
        return $this->validated('scope');
    }
}
