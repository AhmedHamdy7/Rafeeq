<?php

namespace App\Http\Resources;

use App\Domains\Driver\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A vehicle as its own driver sees it.
 *
 * `plate_normalized` is absent: it is an internal uniqueness key, and echoing
 * it back invites a client to key on it. `documents` are summarised by type and
 * status only — a document's path never appears in any payload (pitfall #23).
 *
 * @mixin Vehicle
 */
final class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $documents = [];

        // foreach, not array_map: the OpenAPI generator can type the elements
        // of a list built this way and cannot type the other (see
        // OpenApiDocumentTest, "describes every field concretely").
        foreach ($this->whenLoaded('documents', fn () => $this->documents, fn () => []) as $document) {
            $documents[] = [
                'id' => $document->id,
                'type' => $document->type->value,
                'status' => strtoupper($document->verification_status->value),
                'expiresAt' => $document->expires_at?->toDateString(),
            ];
        }

        return [
            'id' => $this->id,
            'make' => $this->make,
            'model' => $this->model,
            'year' => $this->year,
            'colour' => $this->colour,
            'plateNumber' => $this->plate_number,
            // What you entered on the form: total seats including yours. It does NOT
            // limit a commute — `approvedSeats` does.
            'seats' => $this->seats,
            /*
             * Set by RAFEEQ at the car review (F2-6, PROF-28): the seats with working
             * belts you may sell, at least 3. No commute or weekday may offer more, and
             * the seat price is divided by it. Null until the car has been reviewed —
             * a car without it cannot publish (`VEHICLE_SEATS_NOT_APPROVED`).
             */
            'approvedSeats' => $this->approved_seats,
            // `standard` or `comfort`, set at the review (PROF-26). Comfort is priced at
            // a higher km rate and shown as a badge to passengers.
            'tier' => $this->tierOrDefault()->value,
            // Air conditioning, as checked by the reviewer.
            'hasAc' => (bool) $this->has_ac,
            // `manual` · `automatic` · null (F2-5).
            'transmission' => $this->transmission?->value,
            'fuelType' => $this->fuel_type?->value,
            'isActive' => $this->is_active,
            'status' => strtoupper($this->verification_status->value),
            'suspensionReason' => $this->suspension_reason,
            'registrationRenewalSubmittedAt' => $this->registration_renewal_submitted_at?->toIso8601String(),
            'rejectionReason' => $this->rejection_reason,
            'documents' => $documents,
        ];
    }
}
