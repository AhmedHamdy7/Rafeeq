<?php

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\VerificationQueue;
use App\Livewire\Admin\VerificationQueue as QueuePage;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The identity review queue — the page that unblocks the whole product.
 *
 * Until it existed, `ReviewVerificationAction` was reachable only from the test suite
 * and the seeder, which meant nobody could be verified in production at all.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->reviewer = adminWithRole(AdminRole::Verification);

    // Somebody actually waiting: profile complete, documents uploaded, submitted.
    $this->token = signIn(phone: '01112223344', devicePublicId: 'pax-1')['session']['accessToken'];
    submitGovernmentId($this->token);

    $this->waiting = UserVerification::query()
        ->where('type', VerificationType::GovernmentId->value)
        ->where('status', VerificationStatus::Pending->value)
        ->sole();
});

it('shows the people who are waiting', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(QueuePage::class)
        ->assertOk()
        ->assertSee('مريم حسن')
        ->assertSee(__('admin.queue.approve'));
});

it('approves a level and records who did it', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(QueuePage::class)
        ->call('approve', $this->waiting->id)
        ->assertHasNoErrors();

    expect($this->waiting->refresh()->status)->toBe(VerificationStatus::Approved)
        ->and($this->waiting->reviewed_by)->toBe($this->reviewer->id);

    $entry = AdminAction::query()->where('action', 'verification.approve')->sole();

    expect($entry->admin_id)->toBe($this->reviewer->id)
        ->and($entry->entity_type)->toBe('UserVerification')
        ->and($entry->entity_id)->toBe($this->waiting->id)
        // Before and after, so the row is readable two years later without having to
        // reconstruct what the state used to be.
        ->and($entry->old_value)->toBe(['status' => 'pending'])
        ->and($entry->new_value)->toBe(['status' => 'approved']);
});

/**
 * "Ask info" is the queue's second button, and the reason is shown to the person
 * verbatim — so an empty or useless one is refused.
 */
it('asks for more with a reason the person can act on', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(QueuePage::class)
        ->call('requestInfo', $this->waiting->id)
        // Nothing typed yet.
        ->assertHasErrors('reason')
        ->set('reason', 'The back of your ID is cut off. Please retake it in good light.')
        ->call('requestInfo', $this->waiting->id)
        ->assertHasNoErrors();

    $this->waiting->refresh();

    // `action_needed`, not `rejected`: the person can fix this and try again.
    expect($this->waiting->status)->toBe(VerificationStatus::ActionNeeded)
        ->and($this->waiting->rejection_reason)->toContain('cut off');

    expect(AdminAction::query()->where('action', 'verification.request_info')->sole()->reason)
        ->toContain('cut off');
});

it('refuses a reason too short to act on', function () {
    actingAsAdmin($this->reviewer);

    Livewire::test(QueuePage::class)
        ->set('reason', 'no')
        ->call('requestInfo', $this->waiting->id)
        ->assertHasErrors('reason');

    expect($this->waiting->refresh()->status)->toBe(VerificationStatus::Pending);
});

/**
 * 🔴 The permission split that matters most.
 *
 * A support agent answering "where is my verification" can see the queue is moving.
 * They cannot open the document and cannot decide. The day one support account is
 * phished, that distinction is the only thing between an attacker and every identity
 * document on the platform.
 */
it('lets support see the queue and not decide it', function () {
    $support = adminWithRole(AdminRole::Support);

    expect($support->can(AdminPermission::VerificationView->value))->toBeTrue()
        ->and($support->can(AdminPermission::VerificationDecide->value))->toBeFalse()
        ->and($support->can(AdminPermission::VerificationViewDocument->value))->toBeFalse();

    actingAsAdmin($support);

    Livewire::test(QueuePage::class)
        ->assertOk()
        // The buttons are not rendered...
        ->assertDontSee(__('admin.queue.approve'))
        ->assertSee(__('admin.queue.view_only'))
        // ...and calling the action directly is refused, because a hidden button is
        // not a permission check.
        ->call('approve', $this->waiting->id)
        ->assertForbidden();

    expect($this->waiting->refresh()->status)->toBe(VerificationStatus::Pending);
});

it('keeps the queue away from a role with no verification permission at all', function () {
    actingAsAdmin(adminWithRole(AdminRole::Finance));

    Livewire::test(QueuePage::class)->assertForbidden();
});

it('needs an admin session, not a member one', function () {
    // No admin signed in at all.
    $this->get(route('admin.verifications'))->assertRedirect(route('admin.login'));
});

/**
 * 🔒 A session that never passed the second factor is not an admin session, whatever
 * the guard says. This covers any future path that calls Auth::login() directly.
 */
it('bounces a session that skipped the second factor', function () {
    $this->actingAs($this->reviewer, 'admin');

    $this->get(route('admin.verifications'))->assertRedirect(route('admin.login'));
});

it('signs out a session left idle past the limit', function () {
    actingAsAdmin($this->reviewer);

    $this->get(route('admin.verifications'))->assertOk();

    $this->travel((int) config('rafeeq.admin.session_idle_minutes') + 1)->minutes();

    $this->get(route('admin.verifications'))->assertRedirect(route('admin.login'));
});

/**
 * Oldest first is a promise to the person waiting, not a preference: any other order
 * lets somebody sit behind new arrivals indefinitely.
 */
it('puts the longest wait at the top', function () {
    $second = signIn(phone: '01222220002', devicePublicId: 'pax-2')['session']['accessToken'];
    submitGovernmentId($second);

    // The first submission is aged so the order is unambiguous.
    $this->waiting->forceFill(['submitted_at' => now()->subHours(4)])->save();

    $cards = VerificationQueue::pending();

    expect($cards->first()['id'])->toBe($this->waiting->id)
        ->and($cards)->toHaveCount(2);
});

it('marks a level that has been round the loop before', function () {
    $this->waiting->forceFill(['attempt_count' => 3])->save();

    $card = VerificationQueue::card($this->waiting->refresh());

    // Repeated trips are worth a second look, not a refusal.
    expect($card['risk'])->toBe(VerificationQueue::WARN)
        ->and(collect($card['checks'])->pluck('label'))->toContain(__('admin.queue.attempts'));
});

it('refuses to decide a level somebody else already decided', function () {
    actingAsAdmin($this->reviewer);

    $page = Livewire::test(QueuePage::class);

    // Another reviewer gets there first.
    approveVerification(VerificationType::GovernmentId);

    // 404-shaped rather than a crash or a second approval.
    $page->call('approve', $this->waiting->id)->assertNotFound();
});
