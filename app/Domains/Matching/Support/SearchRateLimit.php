<?php

namespace App\Domains\Matching\Support;

use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use App\Domains\Shared\Support\RateLimits;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How often one account may run the search engine (Chapter 5).
 *
 * 🔴 Here rather than in the controller that happens to need it, because **two endpoints spend the
 * same money**. The pipeline ends in routing-provider work, and the existing note on the search
 * endpoint puts it plainly: an unbounded search endpoint is a way to spend someone else's money.
 * `GET /bookings/{booking}/alternatives` runs the identical engine with the question filled in from
 * a booking, so an endpoint that was limited and an endpoint that was not would be one limit with a
 * door beside it.
 *
 * 🔴 **One bucket, deliberately shared.** Two separate allowances would let a caller alternate
 * between them and spend double. The cost is the same whichever route asked.
 */
final readonly class SearchRateLimit
{
    public static function assert(string $userId): void
    {
        if (! RateLimits::enabled()) {
            return;
        }

        $key = 'search:commutes:'.$userId;
        $limit = (int) config('rafeeq.matching.searches_per_user_per_minute');

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            throw DomainException::retryAfter(ErrorCode::TooManyRequests, RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);
    }
}
