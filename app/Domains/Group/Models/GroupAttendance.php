<?php

namespace App\Domains\Group\Models;

use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Models\Attendance;
use Database\Factories\GroupAttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Planned/declared attendance ("I'm coming tomorrow") — not the actual
 * check-in. See {@see Attendance} (ERD group ⑩)
 * for what actually drives billing.
 */
#[Fillable(['commute_group_id', 'scheduled_trip_id', 'user_id', 'status', 'marked_at'])]
class GroupAttendance extends Model
{
    /** @use HasFactory<GroupAttendanceFactory> */
    use HasFactory, HasUlids;

    // "Attendance" is uncountable in the ERD's naming — Eloquent would
    // otherwise guess "group_attendances".
    protected $table = 'group_attendance';

    protected function casts(): array
    {
        return [
            'status' => GroupAttendanceStatus::class,
            'marked_at' => 'datetime',
        ];
    }

    public function commuteGroup(): BelongsTo
    {
        return $this->belongsTo(CommuteGroup::class);
    }

    public function scheduledTrip(): BelongsTo
    {
        return $this->belongsTo(ScheduledTrip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
