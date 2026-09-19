<?php

namespace App\Domains\Commute\Enums;

enum CommuteOfferStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Paused = 'paused';
    case Archived = 'archived';

    /** The state machine documented in ERD §7 — one place to check a transition. */
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Draft => [self::Published],
            self::Published => [self::Paused, self::Archived],
            self::Paused => [self::Published, self::Archived],
            self::Archived => [],
        }, true);
    }
}
