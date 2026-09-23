<?php

namespace App\Http\Resources;

use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Support\DriverApplicationChecklist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The driver application as its owner sees it (Chapter 3 §9's review screen).
 *
 * The national ID and licence NUMBER are never rendered — not even to the person
 * who typed them. They are stored encrypted so that nothing has to hold them in
 * the clear, and echoing one back would undo that for the sake of a field the
 * person already knows. Only the expiry date, which they need to check, comes
 * back.
 *
 * @mixin DriverProfile
 */
final class DriverApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'status' => strtoupper($this->status->value),
            'licenceExpiry' => $this->licence_expiry?->toDateString(),
            // Whether each number is on file, without revealing either.
            'hasNationalId' => $this->national_id_hash !== null,
            'hasLicenceNumber' => $this->licence_number_hash !== null,
            'verifiedAt' => $this->verified_at?->toIso8601String(),
            // Written by the reviewer for the applicant, so shown verbatim.
            'rejectionReason' => $this->rejection_reason,
            // What still stands between this application and the review queue.
            'missing' => DriverApplicationChecklist::missingFor($this->resource),
            'vehicles' => VehicleResource::collection($this->whenLoaded('vehicles')),
        ];
    }
}
