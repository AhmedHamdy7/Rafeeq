<?php

namespace App\Http\Resources;

use App\Domains\Trip\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whether one person actually travelled.
 *
 * 🔒 `gps_confidence` is returned, and that is deliberate: the passenger disputing a
 * record is entitled to see what evidence sits beside it. Hiding it would mean arguing
 * against something they cannot see.
 *
 * What is absent is `dispute_resolved_by` — a reviewer's staff id is internal, and a
 * passenger needs the decision, not the name of whoever made it.
 *
 * @mixin Attendance
 */
final class AttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'bookingId' => $this->booking_id,
            'status' => strtoupper($this->status->value),

            /*
             * The passenger's boarding code, so the DRIVER can confirm the number she is told at
             * the kerb. This list is the driver's own run, and the attendance endpoint is already
             * driver-only.
             *
             * 🔒 It is a cross-check, not a credential, and it must not become one: attendance is
             * still decided by `check-in` (D18), which deliberately takes no code — "a PIN fails
             * when somebody is running for the car". A driver whose passenger cannot produce the
             * number still checks her in if it is plainly her.
             */
            'boardingCode' => $this->booking?->boarding_code,

            /*
             * Stated rather than inferred from the status, because the client would have
             * to hard-code which values count as having travelled — and then disagree with
             * the server the first time one is added.
             */
            'travelled' => $this->status->travelled(),

            'checkedInAt' => $this->checked_in_at?->toIso8601String(),
            'checkedOutAt' => $this->checked_out_at?->toIso8601String(),

            /*
             * Who decided, and when. `confirmedBy` is 'driver' under decision D18, and it
             * is sent rather than assumed so that the day a second method exists, an old
             * record still says which one produced it.
             */
            'confirmedBy' => $this->confirmed_by,
            'confirmedAt' => $this->confirmed_at?->toIso8601String(),

            /*
             * 🔒 Supporting evidence, never proof — see ConfirmAttendanceAction. Sent so
             * the receipt can say "your driver confirmed this at the meeting point" rather
             * than asking a passenger to take it on trust.
             */
            'gpsCorroborated' => $this->gps_corroborated,
            'gpsConfidence' => $this->gps_confidence === null ? null : (float) $this->gps_confidence,

            'disputedAt' => $this->disputed_at?->toIso8601String(),
            'disputeReason' => $this->dispute_reason,
            'disputeResolution' => $this->dispute_resolution?->value,

            'person' => $this->when(
                $this->resource->relationLoaded('booking') && $this->booking->relationLoaded('passenger'),
                fn () => PersonSummary::for($this->booking->passenger, $request->user()),
            ),
        ];
    }
}
