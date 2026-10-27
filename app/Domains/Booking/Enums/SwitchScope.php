<?php

namespace App\Domains\Booking\Enums;

/**
 * How much of a recurring commitment a switch moves (Feature 03 v5 §150, §187, §231).
 *
 * > *"`POST /bookings/{id}/switch` — One date/future weekdays, cancellation reason, old waitlist
 * > opt-in."* And: *"For per-date switches scope membership to that date; recurring scope must
 * > reflect selected future weekdays."*
 *
 * 🔴 The difference is not a filter on which rows move — it is a difference in what she is giving
 * up, and only one of the two can be undone by waiting. A single date costs her one morning; all
 * future weekdays costs her a standing seat in somebody's car that nothing will give back, because
 * v5 is explicit that **replacement capacity is not guaranteed** on days she has not actually
 * confirmed.
 */
enum SwitchScope: string
{
    /**
     * This morning only. Every other day she holds on the old commute is untouched.
     *
     * The default, and deliberately: it is the recoverable choice, and a client that forgets to
     * send a scope must not accidentally end a passenger's whole term.
     */
    case ThisDate = 'this_date';

    /**
     * This morning and every future day she holds on the old commute.
     *
     * 🔴 **She ends up with ONE new seat and loses all of them.** v5 §231: *"release old affected
     * future bookings and their reserved capacity; replacement capacity is not guaranteed on dates
     * not actually confirmed. UI must clearly warn."* An offer is for one occurrence, so accepting
     * one cannot possibly replace a month — and the honest implementation is to do exactly what she
     * asked and report what it cost, not to pretend the new commute will cover the rest.
     *
     * ⚠️ What this is NOT: a per-weekday selection. v5 lists *"recurring switch for dates with
     * mixed availability"* among the things it has not specified, so the subset case — some future
     * days replaceable, some not — is deliberately absent rather than guessed at. This scope is all
     * of them or none.
     */
    case AllFutureWeekdays = 'all_future_weekdays';

    /**
     * Whether this scope reaches beyond the one day she is switching off.
     */
    public function reachesFutureDays(): bool
    {
        return $this === self::AllFutureWeekdays;
    }
}
