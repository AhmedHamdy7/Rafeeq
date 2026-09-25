<?php

namespace App\Domains\Commute\Actions;

use App\Domains\Commute\Enums\CommuteOfferStatus;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;

/**
 * Which edits a commute allows in which state (Chapter 4's state machine).
 *
 * The transitions themselves live on `CommuteOfferStatus::canTransitionTo()`
 * from Phase 1. What lives here is the question that is about the flow rather
 * than the word: may the driver still change this?
 *
 * A DRAFT is fully editable. A PUBLISHED or PAUSED commute may have its price,
 * seats and preferences changed — those affect future trips only — but not its
 * route or schedule, because passengers have already booked specific days on the
 * strength of both. An ARCHIVED one is history and changes nothing.
 */
final class CommuteState
{
    public static function isEditable(CommuteOffer $offer): bool
    {
        return $offer->status === CommuteOfferStatus::Draft;
    }

    /**
     * Terms a driver may revise after publishing: what a seat costs, how many
     * there are, and the house rules. Each takes effect on trips generated from
     * now on, never on a day someone already booked.
     */
    public static function isRevisable(CommuteOffer $offer): bool
    {
        return $offer->status === CommuteOfferStatus::Draft
            || $offer->status === CommuteOfferStatus::Published
            || $offer->status === CommuteOfferStatus::Paused;
    }

    public static function assertEditable(CommuteOffer $offer): void
    {
        if (! self::isEditable($offer)) {
            throw DomainException::of(ErrorCode::CommuteNotEditable);
        }
    }

    public static function assertRevisable(CommuteOffer $offer): void
    {
        if (! self::isRevisable($offer)) {
            throw DomainException::of(ErrorCode::CommuteNotEditable);
        }
    }

    public static function assertCanTransitionTo(CommuteOffer $offer, CommuteOfferStatus $next): void
    {
        if (! $offer->status->canTransitionTo($next)) {
            throw DomainException::of(ErrorCode::CommuteInvalidTransition, fields: [
                'status' => [strtoupper($offer->status->value)],
            ]);
        }
    }
}
