<?php

namespace App\Domains\Safety\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Enums\IncidentCategory;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Enums\SafetySeverity;
use App\Domains\Trip\Models\TripSession;
use Database\Factories\IncidentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['booking_id', 'trip_session_id', 'reporter_user_id', 'reported_user_id', 'category', 'severity', 'description'])]
class Incident extends Model
{
    /** @use HasFactory<IncidentFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'category' => IncidentCategory::class,
            'severity' => SafetySeverity::class,
            'status' => IncidentStatus::class,
            'sla_due_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function tripSession(): BelongsTo
    {
        return $this->belongsTo(TripSession::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reportedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assigned_admin_id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(IncidentEvidence::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [IncidentStatus::Open, IncidentStatus::UnderReview, IncidentStatus::Escalated], true);
    }
}
