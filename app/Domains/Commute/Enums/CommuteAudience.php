<?php

namespace App\Domains\Commute\Enums;

/**
 * 🔒 The hard filter (Bible §15.2): `women_only` must exclude non-women
 * entirely from search results, never just score them lower — pitfall #15.
 */
enum CommuteAudience: string
{
    case WomenOnly = 'women_only';
    case AnyVerified = 'any_verified';
}
