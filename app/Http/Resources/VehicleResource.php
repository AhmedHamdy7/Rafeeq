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
            // Total seats including the driver's; bookable seats are one fewer.
            'seats' => $this->seats,
            'transmission' => $this->transmission,
            'fuelType' => $this->fuel_type?->value,
            'isActive' => $this->is_active,
            'status' => strtoupper($this->verification_status->value),
            'documents' => $documents,
        ];
    }
}
