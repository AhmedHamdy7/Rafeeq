<?php

namespace App\Domains\Driver\Models;

use App\Domains\Driver\Enums\VehicleDocumentType;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use Database\Factories\VehicleDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['vehicle_id', 'type', 'file_path', 'file_hash', 'expires_at', 'purge_after'])]
#[Hidden(['file_path'])]
class VehicleDocument extends Model
{
    /** @use HasFactory<VehicleDocumentFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => VehicleDocumentType::class,
            'verification_status' => VehicleVerificationStatus::class,
            'expires_at' => 'date',
            'purge_after' => 'date',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
