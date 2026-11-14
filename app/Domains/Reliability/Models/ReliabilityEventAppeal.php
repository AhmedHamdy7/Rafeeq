<?php

namespace App\Domains\Reliability\Models;

use App\Domains\Identity\Models\User;
use App\Domains\Reliability\Enums\AppealDeclineReason;
use App\Domains\Trip\Enums\DisputeResolution;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReliabilityEventAppeal extends Model
{
    use HasUlids;

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'evidence_count' => 'integer',
            'resolution' => DisputeResolution::class,
            'decline_reason_code' => AppealDeclineReason::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ReliabilityEvent::class, 'reliability_event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->resolution === null;
    }

    public function status(): string
    {
        return match ($this->resolution) {
            null => 'pending',
            DisputeResolution::Overturned => 'approved',
            default => 'declined',
        };
    }
}
