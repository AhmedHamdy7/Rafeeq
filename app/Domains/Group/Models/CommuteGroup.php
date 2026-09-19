<?php

namespace App\Domains\Group\Models;

use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Group\Enums\CommuteGroupStatus;
use Database\Factories\CommuteGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['commute_offer_id', 'name', 'min_commitment_days_per_week', 'notice_period_days'])]
class CommuteGroup extends Model
{
    /** @use HasFactory<CommuteGroupFactory> */
    use HasFactory, HasUlids;

    protected function casts(): array
    {
        return [
            'status' => CommuteGroupStatus::class,
            'min_commitment_days_per_week' => 'integer',
            'on_time_pct' => 'decimal:2',
            'rides_together_count' => 'integer',
            'seats_open' => 'integer',
            'notice_period_days' => 'integer',
        ];
    }

    public function commuteOffer(): BelongsTo
    {
        return $this->belongsTo(CommuteOffer::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(GroupAttendance::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(GroupAbsence::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === CommuteGroupStatus::Active;
    }
}
