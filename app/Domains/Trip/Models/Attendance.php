<?php

namespace App\Domains\Trip\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Booking\Models\Booking;
use App\Domains\Group\Models\GroupAttendance;
use App\Domains\Identity\Models\User;
use App\Domains\Trip\Enums\AttendanceStatus;
use App\Domains\Trip\Enums\DisputeResolution;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The ACTUAL check-in (decision D18: the driver confirms). See
 * {@see GroupAttendance} for planned/declared
 * attendance, a different table with a different meaning.
 */
// Only `booking_id` is mass-assignable. Attendance is what triggers billing
// (decision D18), so `status`, `confirmed_by*` and the GPS corroboration
// fields must be set explicitly by the confirmation Action — never carried in
// on a request payload (pitfall #51).
#[Fillable(['booking_id'])]
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    // "Attendance" is uncountable in the ERD's naming — Eloquent would
    // otherwise guess "attendances" (see RAFEEQ_PROGRESS.md standard #21).
    protected $table = 'attendance';

    protected $primaryKey = 'booking_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'status' => AttendanceStatus::class,
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'gps_corroborated' => 'boolean',
            'gps_confidence' => 'decimal:2',
            'disputed_at' => 'datetime',
            'dispute_resolution' => DisputeResolution::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    public function disputeResolvedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'dispute_resolved_by');
    }

    public function isDisputed(): bool
    {
        return $this->disputed_at !== null && $this->dispute_resolution === null;
    }
}
