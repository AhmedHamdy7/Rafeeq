<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Notification;
use App\Domains\Rating\Enums\ModerationStatus;
use App\Domains\Rating\Enums\ReviewReportStatus;
use App\Domains\Rating\Models\Rating;
use App\Domains\Rating\Models\ReviewReport;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Livewire\Admin\MemberProfile;
use Livewire\Livewire;

/**
 * Reliability & ratings tab: reversing an event, answering an appeal, moderating a review.
 */
beforeEach(function () {
    $this->member = User::factory()->create();
});

function runRecordAction(User $member, string $action, string $target, string $reason = 'Checked the trip log')
{
    return Livewire::test(MemberProfile::class, ['user' => $member])
        ->set('tab', 'reliability')
        ->call('startAction', $action, $target)
        ->set('reason', $reason)
        ->call('runAction');
}

it('reverses an event without telling her about an appeal she never filed', function () {
    $event = ReliabilityEvent::factory()->create(['user_id' => $this->member->id]);
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    runRecordAction($this->member, 'reverse_event', $event->id)->assertHasNoErrors()->assertSet('done', __('admin/actions.done.reverse_event'));

    expect($event->refresh()->reversed_at)->not->toBeNull()
        ->and(AdminAction::query()->where('action', 'reliability.reverse')->sole()->entity_id)->toBe($this->member->id)
        ->and(Notification::query()->where('user_id', $this->member->id)->exists())->toBeFalse();
});

it('approves an open appeal by reversing, and declines one that stays counting', function () {
    $appealed = ReliabilityEvent::factory()->create(['user_id' => $this->member->id]);
    $appealed->forceFill(['appealed_at' => now(), 'appeal_reason' => 'The driver never came'])->save();
    $declined = ReliabilityEvent::factory()->create(['user_id' => $this->member->id]);
    $declined->forceFill(['appealed_at' => now(), 'appeal_reason' => 'I was ill'])->save();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    runRecordAction($this->member, 'reverse_event', $appealed->id)->assertHasNoErrors();
    runRecordAction($this->member, 'decline_appeal', $declined->id)->assertHasNoErrors();

    expect($appealed->refresh()->reversed_at)->not->toBeNull()
        ->and($declined->refresh()->reversed_at)->toBeNull()
        ->and($declined->appeal_declined_at)->not->toBeNull()
        ->and(Notification::query()->where('user_id', $this->member->id)->pluck('type')->map(fn ($t) => $t instanceof BackedEnum ? $t->value : $t)->sort()->values()->all())
        ->toBe(collect([NotificationType::AppealApproved->value, NotificationType::AppealDeclined->value])->sort()->values()->all());
});

it('refuses to decline when there is no open appeal', function () {
    $event = ReliabilityEvent::factory()->create(['user_id' => $this->member->id]);
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    runRecordAction($this->member, 'decline_appeal', $event->id)->assertHasErrors('record');
});

it('hides a reported review, closing its complaints, and shows it again', function () {
    $rating = Rating::factory()->visible()->create(['reviewed_user_id' => $this->member->id]);
    $report = ReviewReport::factory()->create(['rating_id' => $rating->id]);
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    runRecordAction($this->member, 'hide_rating', $rating->id)->assertHasNoErrors();

    expect($rating->refresh()->moderation_status)->toBe(ModerationStatus::Hidden)
        ->and($report->refresh()->status)->toBe(ReviewReportStatus::Upheld);

    runRecordAction($this->member, 'restore_rating', $rating->id)->assertHasNoErrors();

    expect($rating->refresh()->moderation_status)->toBe(ModerationStatus::Clean)
        ->and(AdminAction::query()->whereIn('action', ['rating.hide', 'rating.restore'])->count())->toBe(2);
});

it('never touches another member’s record from this page', function () {
    $someoneElse = ReliabilityEvent::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    runRecordAction($this->member, 'reverse_event', $someoneElse->id)->assertNotFound();
    expect($someoneElse->refresh()->reversed_at)->toBeNull();
});

it('keeps the buttons from staff who may not change a record', function () {
    ReliabilityEvent::factory()->create(['user_id' => $this->member->id]);
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(MemberProfile::class, ['user' => $this->member])
        ->set('tab', 'reliability')
        ->assertDontSee(__('admin/actions.titles.reverse_event'));
});

it('has the new strings in both languages', function () {
    foreach (['titles', 'hints', 'confirm', 'done'] as $group) {
        expect(array_keys(trans("admin/actions.$group", [], 'ar')))->toBe(array_keys(trans("admin/actions.$group", [], 'en')));
    }
});
