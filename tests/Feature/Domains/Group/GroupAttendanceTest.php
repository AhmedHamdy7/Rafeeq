<?php

use App\Domains\Group\Enums\GroupAttendanceStatus;
use App\Domains\Group\Models\GroupAttendance;
use App\Domains\Trip\Models\Attendance;
use Illuminate\Database\QueryException;

it('rejects a second declared-attendance row for the same member on the same trip', function () {
    $attendance = GroupAttendance::factory()->create();

    GroupAttendance::factory()->create([
        'commute_group_id' => $attendance->commute_group_id,
        'scheduled_trip_id' => $attendance->scheduled_trip_id,
        'user_id' => $attendance->user_id,
    ]);
})->throws(QueryException::class);

it('is distinct from actual trip-day attendance (different table, different meaning)', function () {
    $attendance = GroupAttendance::factory()->create(['status' => GroupAttendanceStatus::Away]);

    expect($attendance->status)->toBe(GroupAttendanceStatus::Away)
        ->and($attendance->getTable())->toBe('group_attendance')
        ->and(Attendance::class)->not->toBe(GroupAttendance::class);
});
