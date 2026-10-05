<?php

namespace App\Http\Resources;

use App\Domains\Notification\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message in a trip chat, from the caller's side of it.
 *
 * `mine` instead of a sender id: there are exactly two people in the conversation, and a user
 * id in a payload is something the rest of the API is careful never to hand out.
 *
 * @mixin Message
 */
final class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // True when the caller wrote it.
            'mine' => $this->sender_user_id === $request->user()->id,

            'body' => $this->body,

            /*
             * The message contains something that looks like a phone number or an email. Allowed,
             * but show the recipient a caution before they use it — numbers shared here leave the
             * platform's protections behind.
             */
            'containsContactInfo' => $this->contains_contact_info,

            // When the other person opened the conversation after it arrived. `null` until then.
            'readAt' => $this->read_at?->toIso8601String(),
            'sentAt' => $this->created_at->toIso8601String(),
        ];
    }
}
