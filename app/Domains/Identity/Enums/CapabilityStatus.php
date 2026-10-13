<?php

namespace App\Domains\Identity\Enums;

/**
 * Whether an enrolment the person made is still in effect.
 *
 * 🔒 This is about what the PERSON chose, never about what staff decided. A capability withdrawn
 * here is self-service and can be re-enrolled by the same call that created it.
 *
 * A staff restriction is a different thing entirely and lives in `account_suspensions` with
 * `scope = driver`, so that it carries a case number, a reason, a review deadline and an audit
 * trail. Representing both as `withdrawn` here would let somebody lift a safety restriction by
 * re-submitting their own role choice, which is exactly the hole this separation closes.
 */
enum CapabilityStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';
}
