<?php

namespace App\Http\Resources;

use App\Domains\Notification\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One item in the member's inbox.
 *
 * `title` and `body` are already in the member's language — rendered when the message was sent,
 * and stored as written. Route on `type` and `data`; show the text as it is.
 *
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            // What happened — `seat_approved`, `trip_started`, `sos_picked_up`… The screen to open.
            'type' => $this->type,

            // `booking` · `payment` · `trip` · `safety` · `marketing`.
            'category' => $this->category->value,

            'title' => $this->title,
            'body' => $this->body,

            // Ids for the screen to open (`seatRequestId`, `tripId`, `incidentId`, `sosId`…).
            // Never names or numbers.
            'data' => (object) ($this->data ?? []),

            'isRead' => $this->read_at !== null,
            'readAt' => $this->read_at?->toIso8601String(),
            'createdAt' => $this->created_at->toIso8601String(),
        ];
    }
}
