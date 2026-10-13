<?php

namespace App\Domains\Identity\Enums;

/**
 * How much of an account a staff restriction covers (Feature 01 v3 §8).
 *
 * 🔒 The distinction is the point, and getting it backwards is the expensive mistake:
 *
 *   - `Account` — the person cannot use Rafeeq. Routed to `RESTRICTED_ACCOUNT` with a way to reach
 *     support, and nothing else is reachable.
 *   - `Driver` — the person keeps **full passenger access**. She goes Home as normal, and only the
 *     driver surface refuses. Treating this as an account restriction would lock somebody out of
 *     the half of the product she is still entitled to use, over a matter concerning the other
 *     half.
 *
 * Every existing row predates the distinction and therefore means `Account`, which is why the
 * column defaults to it rather than being nullable.
 */
enum RestrictionScope: string
{
    case Account = 'account';
    case Driver = 'driver';
}
