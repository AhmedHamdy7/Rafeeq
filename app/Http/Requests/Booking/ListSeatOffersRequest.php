<?php

namespace App\Http\Requests\Booking;

use App\Domains\Booking\Enums\SeatOfferStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which of her seat offers `GET /seat-offers` returns (TRIPS-14).
 *
 * ---
 *
 * Maintainer notes:
 *
 * The filter is the server's because paging is: offers are split open vs history on the phone,
 * and reading every page to find the open one is what this replaces.
 */
final class ListSeatOffersRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Comma-separated, any case: `OPEN`, or `ACCEPTED,DECLINED,TAKEN_BY_OTHER`. Each must be an offer
             * status (`OPEN` · `ACCEPTED` · `TAKEN_BY_OTHER` · `INELIGIBLE` · `CLOSED` · `DECLINED` ·
             * `WITHDRAWN`). Left out: every offer, open ones first, as before.
             */
            'status' => ['nullable', 'string', 'max:300', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (self::split($value) as $status) {
                    if (SeatOfferStatus::tryFrom($status) === null) {
                        $fail(__('validation.in', ['attribute' => $attribute]));

                        return;
                    }
                }
            }],
        ];
    }

    /**
     * @return list<string> the stored values, lower case
     */
    public function statuses(): array
    {
        return self::split($this->validated('status'));
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (string $part) => strtolower(trim($part)),
            explode(',', $value),
        ))));
    }
}
