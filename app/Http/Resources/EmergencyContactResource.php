<?php

namespace App\Http\Resources;

use App\Domains\Safety\Models\EmergencyContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trusted contact, shown ONLY to the person who added them.
 *
 * 🔒 There is no endpoint anywhere that returns somebody else's contact list, and no payload in
 * which one member's contacts reach another. The phone number is `#[Hidden]` on the model by
 * default and revealed here, in this one resource, on the owner's own request — so forgetting to
 * hide it somewhere else is impossible rather than merely unlikely.
 *
 * @mixin EmergencyContact
 */
final class EmergencyContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            /*
             * The full number, because this is the owner reading their own list and a masked number
             * cannot be checked for a typo — which is the whole reason somebody opens this screen.
             */
            'phone' => $this->phone_e164,

            'relationship' => $this->relationship,

            /*
             * 🔒 Whether this contact sees EVERY trip automatically, not just the ones shared
             * deliberately. Returned prominently because it is the setting that matters most and the
             * one somebody may not remember turning on: a contact with this enabled has a standing
             * feed of where its owner goes every morning.
             */
            'autoShareTrips' => $this->auto_share_trips,

            // Elevated access during an emergency.
            'isGuardian' => $this->is_guardian,

            /*
             * Whether the number has been confirmed to actually receive messages. **Null today for
             * everybody**: confirming it needs an OTP to that number, which is Phase 12. Sent as
             * null rather than omitted so the screen can say "unconfirmed" honestly instead of
             * implying the contact will be reached.
             */
            'verifiedAt' => $this->verified_at?->toIso8601String(),

            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
