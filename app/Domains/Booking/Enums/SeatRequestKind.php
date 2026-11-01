<?php

namespace App\Domains\Booking\Enums;

/**
 * What she is asking the driver for (Feature 03 R-1/R-2, BOOK-18, BOOK-34).
 *
 * 🔴 A separate axis from {@see SeatRequestStatus}, and it has to be. The status says where the
 * conversation is (`pending`, `waitlisted`, `approved`…); the kind says what the conversation is
 * ABOUT. A request on a full day and a request on a free one are both `pending` while the driver
 * decides — what differs is the question he is being asked, and so which button his inbox puts
 * first:
 *
 * - `seat_request` — "may I ride with you on these days?" His yes seats her.
 * - `waitlist_application` — "may I join your waiting list?" His yes puts her on the list
 *   (`POST /driver/seat-requests/{id}/waitlist`), and a freed seat is then offered to her with
 *   everybody else's (BOOK-20).
 *
 * Stored rather than worked out on read, because it records the circumstances she asked in — the
 * day was full, or she already had a confirmed seat at that time — and a later seat freeing up does
 * not change what she applied for. It can move one way only: a seat request becomes an
 * application when somebody else fills the day, or when another driver confirms her for the same
 * time (BOOK-34). Nothing turns an application back into a seat request; she withdraws and asks
 * again (BOOK-19).
 */
enum SeatRequestKind: string
{
    case SeatRequest = 'seat_request';
    case WaitlistApplication = 'waitlist_application';
}
