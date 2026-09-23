<?php

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Driver\Actions\ReviewDriverApplicationAction;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Driver\Models\VerificationLog;
use App\Domains\Identity\Enums\SecurityEventType;
use App\Domains\Identity\Models\SecurityEvent;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Storage;

/**
 * Chapter 3 §10, §11, §12.
 *
 * The HTTP surface for a reviewer's decision belongs to the admin dashboard
 * (Phase 13), so these drive the Action the way that dashboard will — which is
 * also how the states the applicant sees come to exist at all.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->token = readyDriverApplicant();
    completeDriverApplication($this->token);

    test()->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();

    $this->reviewer = AdminUser::factory()->create();
    $this->review = app(ReviewDriverApplicationAction::class);
});

it('approves the driver and the car they were reviewed with', function () {
    $this->review->approve(DriverProfile::sole(), $this->reviewer);

    $profile = DriverProfile::sole();

    expect($profile->status)->toBe(DriverProfileStatus::Approved)
        ->and($profile->verified_at)->not->toBeNull()
        ->and($profile->reviewer_id)->toBe($this->reviewer->id)
        // Leaving the vehicle pending would produce an approved driver who
        // still cannot publish anything, with nothing explaining why.
        ->and(Vehicle::sole()->verification_status)->toBe(VehicleVerificationStatus::Approved);
});

/**
 * §12: who decided, when, the old value, the new value, and why — in a table
 * that is append-only, so the record cannot be edited by whoever made the
 * decision.
 */
it('writes an audit row with the old and new value', function () {
    $this->review->approve(DriverProfile::sole(), $this->reviewer);

    $log = VerificationLog::where('entity_type', 'driver_profile')->sole();

    expect($log->entity_id)->toBe(DriverProfile::sole()->user_id)
        ->and($log->admin_id)->toBe($this->reviewer->id)
        ->and($log->action->value)->toBe('approve')
        ->and($log->old_value)->toBe(['status' => 'pending_review'])
        ->and($log->new_value)->toBe(['status' => 'approved']);
});

it('will not let a decision be edited or deleted afterwards', function () {
    $this->review->approve(DriverProfile::sole(), $this->reviewer);

    $log = VerificationLog::sole();

    expect(fn () => $log->update(['reason' => 'rewritten']))->toThrow(RuntimeException::class)
        ->and(fn () => $log->delete())->toThrow(RuntimeException::class);
});

/**
 * §16 story 2: time passes while an application sits in the queue.
 */
it('refuses to approve a licence that expired while queued', function () {
    DriverProfile::sole()->forceFill(['licence_expiry' => now()->subDay()->toDateString()])->save();

    expect(fn () => $this->review->approve(DriverProfile::sole(), $this->reviewer))
        ->toThrow(DomainException::class);

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::PendingReview);
});

/**
 * §10: a rejection reason is mandatory, and §16 story 3 has the applicant
 * fixing only what was wrong — so the state has to reopen editing.
 */
it('rejects with a reason the applicant can act on, and reopens editing', function () {
    $this->review->reject(DriverProfile::sole(), $this->reviewer, 'The vehicle registration photo is blurry.');

    $application = $this->withToken($this->token)
        ->getJson('/api/v1/driver/application')->assertOk()->json('data');

    expect($application['status'])->toBe('REJECTED')
        ->and($application['rejectionReason'])->toBe('The vehicle registration photo is blurry.');

    // And the applicant can correct it and resubmit.
    $this->withToken($this->token)->putJson('/api/v1/driver/application/licence', [
        'nationalId' => '29604120101234',
        'licenceNumber' => 'DL-9931204',
        'licenceExpiry' => now()->addYears(3)->toDateString(),
    ])->assertOk();

    $this->withToken($this->token)->postJson('/api/v1/driver/application/submit')->assertOk();
});

it('refuses a rejection with no reason', function () {
    expect(fn () => $this->review->reject(DriverProfile::sole(), $this->reviewer, '   '))
        ->toThrow(DomainException::class);

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::PendingReview);
});

it('refuses to decide an application that is not under review', function () {
    $this->review->approve(DriverProfile::sole(), $this->reviewer);

    // Deciding twice would let a second reviewer overturn the first silently.
    expect(fn () => $this->review->approve(DriverProfile::sole(), $this->reviewer))
        ->toThrow(DomainException::class);
});

/**
 * A suspended driver whose car stayed approved could otherwise keep carrying
 * passengers through any code path that checks only the vehicle.
 */
it('takes the vehicles down with a suspended driver', function () {
    $this->review->approve(DriverProfile::sole(), $this->reviewer);

    $this->review->suspend(DriverProfile::sole(), $this->reviewer, 'Safety report under investigation.');

    expect(DriverProfile::sole()->status)->toBe(DriverProfileStatus::Suspended)
        ->and(Vehicle::sole()->verification_status)->toBe(VehicleVerificationStatus::Suspended)
        ->and(Vehicle::sole()->is_active)->toBeFalse()
        ->and(SecurityEvent::where('event_type', SecurityEventType::DriverSuspended->value)->count())->toBe(1);
});

it('logs every transition, not only the first', function () {
    $this->review->approve(DriverProfile::sole(), $this->reviewer);
    $this->review->suspend(DriverProfile::sole(), $this->reviewer, 'Under investigation.');

    $actions = VerificationLog::orderBy('created_at')->pluck('action')->map->value->all();

    expect($actions)->toBe(['approve', 'suspend']);
});

it('audits the approval as a security event too', function () {
    $this->review->approve(DriverProfile::sole(), $this->reviewer);

    expect(SecurityEvent::where('event_type', SecurityEventType::DriverApproved->value)->count())->toBe(1);
});
