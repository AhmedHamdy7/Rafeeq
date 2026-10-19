<?php

namespace App\Domains\Booking\Enums;

/**
 * Where a passenger's chosen pickup or drop-off point came from (Feature 03 v5 §5:
 * `source DRIVER_SUGGESTION|CUSTOM_MAP` per point).
 *
 * 🔴 Recorded per point, not per request, because v5 allows them to differ: *"A passenger may
 * select a suggested pickup and custom drop-off, or vice versa, or two custom points."*
 *
 * It matters beyond bookkeeping. A point the driver herself advertised is one she has already
 * agreed to stop at; a point the passenger dropped on the map is a proposal the driver has not seen
 * before, and it is the one she is weighing when she decides whether the detour is reasonable. A
 * screen that cannot tell them apart would present both as equally settled.
 */
enum PointSource: string
{
    /** One of the pickup/drop-off points the driver advertised on her route. */
    case DriverSuggestion = 'driver_suggestion';

    /** A place the passenger chose on the map herself. */
    case CustomMap = 'custom_map';

    /**
     * Neither: the point was written by the migration that made these fields required, from the
     * commute's own start and end.
     *
     * 🔴 It exists so a backfilled row can never be mistaken for a choice somebody made. v5 is
     * explicit that a passenger's points may not be "inferred from the driver's route endpoints" —
     * that rule is about new requests, and honouring it for the ones that already existed means
     * marking them rather than pretending. A driver reviewing such a request sees that nobody picked
     * these points, which is the truth.
     */
    case LegacyBackfill = 'legacy_backfill';

    /** Whether a person chose this point, as opposed to a migration having written it. */
    public function wasChosen(): bool
    {
        return $this !== self::LegacyBackfill;
    }
}
