<?php

namespace App\Domains\Safety\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Safety\Enums\SosResolution;
use App\Domains\Shared\Casts\SpatialPoint;
use Database\Factories\SosEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['safety_event_id', 'countdown_seconds', 'is_discreet', 'location_at_trigger'])]
class SosEvent extends Model
{
    /** @use HasFactory<SosEventFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'countdown_seconds' => 'integer',
            'cancelled_at' => 'datetime',
            'is_discreet' => 'boolean',
            'first_touch_at' => 'datetime',
            'resolution' => SosResolution::class,
            'location_at_trigger' => SpatialPoint::class,
        ];
    }

    public function safetyEvent(): BelongsTo
    {
        return $this->belongsTo(SafetyEvent::class);
    }

    public function responderAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'responder_admin_id');
    }

    public function wasCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function responseSeconds(): ?int
    {
        // abs(): Carbon's diffInSeconds sign depends on call direction, and
        // a response time is never meaningfully negative either way.
        return $this->first_touch_at !== null
            ? abs($this->first_touch_at->diffInSeconds($this->created_at))
            : null;
    }
}
