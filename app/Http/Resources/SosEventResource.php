<?php

namespace App\Http\Resources;

use App\Domains\Safety\Models\SosEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An SOS, back to the person who raised it.
 *
 * 🔒 What is absent: the responder's identity. An operator handling an emergency is a member of
 * staff doing their job, and naming them to the person in the incident gives an angry or unwell
 * caller a human target. `respondedAt` says somebody is on it, which is the part that reassures.
 *
 * @mixin SosEvent
 */
final class SosEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            /*
             * How long the client should count down before treating it as confirmed. Copied onto
             * the row when it was raised, so this is the window that was actually in force.
             *
             * 🔴 The row already exists by the time you read this. The countdown guards against an
             * accidental tap; it does not gate the record. See TriggerSosAction.
             */
            'countdownSeconds' => $this->countdown_seconds,

            // 🔒 Silent: no sound, no vibration. The client must honour it — the server cannot.
            'isDiscreet' => $this->is_discreet,

            'cancelledAt' => $this->cancelled_at?->toIso8601String(),

            /*
             * When a human first picked it up. The one number operations is measured on, and here
             * it is what tells the person that somebody is actually looking.
             */
            'respondedAt' => $this->first_touch_at?->toIso8601String(),

            'resolution' => $this->resolution?->value,
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
