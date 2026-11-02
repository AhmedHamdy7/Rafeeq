<?php

namespace App\Http\Requests\Booking;

use App\Domains\Booking\Enums\SeatRequestStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Which of her seat requests `GET /seat-requests` returns (TRIPS-14).
 *
 * ---
 *
 * Maintainer notes:
 *
 * The filter is the server's because paging is — the same reason as `ListMyBookingsRequest`. The
 * Requests tab wants open requests and the Waiting list tab wants applications and places; filtered
 * on the phone, a page of twenty could hold two of either and `meta.hasMore` would describe a list
 * the screen is not showing.
 */
final class ListSeatRequestsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            /*
             * Comma-separated, any case: `PENDING,COUNTEROFFERED`. Each must be a seat-request
             * status (`PENDING` · `APPROVED` · `REJECTED` · `WAITLISTED` · `WITHDRAWN` · `EXPIRED` ·
             * `CLOSED_DATE_PASSED` · `COMMUTE_ARCHIVED` · `COUNTEROFFERED` · `COUNTEROFFER_DECLINED`
             * · `TERMS_CHANGED` · `ENDED`). Left out: every request, as before.
             */
            'status' => ['nullable', 'string', 'max:300', function (string $attribute, mixed $value, \Closure $fail): void {
                foreach (self::split($value) as $status) {
                    if (SeatRequestStatus::tryFrom($status) === null) {
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
