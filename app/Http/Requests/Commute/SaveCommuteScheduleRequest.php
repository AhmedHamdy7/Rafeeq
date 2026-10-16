<?php

namespace App\Http\Requests\Commute;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * When a commute runs (Chapter 4 §4).
 *
 * ---
 *
 * Maintainer notes:
 *
 * - `departureTime` is a LOCAL wall clock stored with its timezone, never a UTC
 *   instant. Egypt observes DST by law, so "07:05" is a different moment in
 *   April than in December; converting on save would make every trip after a
 *   transition depart an hour wrong. The UTC instant is computed per day at
 *   generation time.
 * - A one-time commute ignores `daysMask` and `endDate`: the Action derives both
 *   from the single date, so every consumer sees the same shape.
 */
final class SaveCommuteScheduleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Which days of the week, as a bitmask: Saturday 1, Sunday 2,
             * Monday 4, Tuesday 8, Wednesday 16, Thursday 32, Friday 64. Sunday
             * to Thursday is 62. Ignored for a one-time commute.
             */
            'daysMask' => ['required_unless:commuteType,one_time', 'integer', 'min:0', 'max:127'],

            // Departure time on your own clock, 24-hour. Not UTC — send the time
            // you would read off a wall.
            'departureTime' => ['required', 'string', 'date_format:H:i:s'],

            // IANA timezone the departure time is read in.
            'timezone' => ['sometimes', 'string', Rule::in(['Africa/Cairo'])],

            // First day this commute runs. Cannot be in the past.
            'startDate' => ['required', 'date', 'date_format:Y-m-d'],

            // Last day it runs. Required: a commute cannot be open-ended. For a
            // one-time commute this is ignored and set to the start date.
            'endDate' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:startDate'],

            /*
             * When you promise to arrive, on your own clock. Optional, and the default for every
             * day unless a day overrides it below.
             *
             * ⚠️ Arriving later than this counts against your on-time rate, outside a five-minute
             * tolerance. Leave it out if you would rather not promise a time.
             */
            'promisedArrivalTime' => ['sometimes', 'nullable', 'string', 'date_format:H:i:s'],

            /*
             * Per-day differences, keyed by the day's bit: `{"2": {"departureTime": "06:45:00"}}`
             * sets Sunday only. Everything you leave out follows the values above, and a day you
             * do not mention runs exactly like the rest of the week.
             *
             * Use it when one day genuinely differs — an early Sunday, a cheaper Thursday, one
             * fewer seat on the day you take your own child.
             */
            'perDay' => ['sometimes', 'array'],

            // The day's bit: 1 Sat, 2 Sun, 4 Mon, 8 Tue, 16 Wed, 32 Thu, 64 Fri.
            'perDay.*.departureTime' => ['sometimes', 'string', 'date_format:H:i:s'],

            'perDay.*.promisedArrivalTime' => ['sometimes', 'nullable', 'string', 'date_format:H:i:s'],

            // What a seat costs on this day, in piastres. Omit to charge your usual price.
            'perDay.*.pricePerSeatPiastres' => ['sometimes', 'integer', 'min:0'],

            // How many seats you offer on this day. Omit to offer your usual number.
            'perDay.*.seatsTotal' => ['sometimes', 'integer', 'min:1', 'max:7'],
        ];
    }
}
