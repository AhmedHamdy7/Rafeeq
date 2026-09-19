<?php

namespace App\Domains\Shared\Concerns;

use RuntimeException;

/**
 * For tables the Bible marks "never deleted" without also marking them
 * INSERT-only (`safety_events`, `incident_evidence` — Bible §4, group ⑫).
 * Unlike {@see IsAppendOnly}, updates are still allowed (e.g. an admin
 * attaching a resolution), only deletion is blocked.
 */
trait PreventsDeletion
{
    public static function bootPreventsDeletion(): void
    {
        static::deleting(function (self $model): void {
            throw new RuntimeException(static::class.' records may never be deleted.');
        });
    }
}
