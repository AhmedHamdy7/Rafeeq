<?php

namespace App\Domains\Shared\Concerns;

use RuntimeException;

/**
 * Application-level enforcement for the tables the Bible marks 🔒
 * INSERT-only (verification_logs, booking_events, driver_fee_ledger,
 * admin_actions, safety_events, incident_evidence). The real guarantee is
 * the database grant (production: `GRANT SELECT, INSERT` only — see
 * Bible §6.2), but failing fast here catches mistakes long before deploy.
 */
trait IsAppendOnly
{
    public static function bootIsAppendOnly(): void
    {
        static::updating(function (self $model): void {
            throw new RuntimeException(static::class.' is append-only and cannot be updated.');
        });

        static::deleting(function (self $model): void {
            throw new RuntimeException(static::class.' is append-only and cannot be deleted.');
        });
    }
}
