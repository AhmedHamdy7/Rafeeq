<?php

namespace App\Domains\Identity\Models;

use App\Domains\Identity\Enums\SecurityRiskLevel;
use Database\Factories\SecurityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'device_id', 'event_type', 'risk_level', 'metadata'])]
class SecurityEvent extends Model
{
    /** @use HasFactory<SecurityEventFactory> */
    use HasFactory, HasUlids, MassPrunable;

    protected function casts(): array
    {
        return [
            'risk_level' => SecurityRiskLevel::class,
            'metadata' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Deleted by the scheduled `model:prune`. 24 months (ERD §18): long enough to investigate an account takeover after the fact.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subMonths((int) config('rafeeq.retention.security_events_months')));
    }
}
