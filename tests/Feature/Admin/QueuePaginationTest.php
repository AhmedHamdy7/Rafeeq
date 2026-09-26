<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Identity\Models\User;
use App\Domains\Verification\Enums\VerificationStatus;
use App\Domains\Verification\Enums\VerificationType;
use App\Domains\Verification\Models\UserVerification;
use App\Domains\Verification\Support\VerificationQueue;
use App\Livewire\Admin\VerificationQueue as QueuePage;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * 🔴 Paging the review queue is a fairness guarantee, not a performance tweak.
 *
 * The queue is ordered oldest-first so nobody sits behind later arrivals. It used to be
 * `limit(50)`, which means the moment more than fifty people are waiting the fifty-first
 * is not merely later — they are INVISIBLE, and stay invisible for as long as the queue
 * stays full. These tests are about every row being reachable.
 */
beforeEach(function () {
    Storage::fake('documents');

    $this->reviewer = adminWithRole(AdminRole::Verification);
});

/**
 * Builds pending verifications straight in the database.
 *
 * Deliberately not through the endpoints: each one would need its own registered,
 * profile-complete account with uploaded documents, and twenty-five of those would make
 * this a test about registration rather than about paging.
 */
function pendingVerifications(int $count, int $startAt = 1): void
{
    foreach (range($startAt, $startAt + $count - 1) as $index) {
        /*
         * Zero-padded, and that is load-bearing for the assertions below: "Waiting
         * Person 1" is a SUBSTRING of "Waiting Person 11", so an `assertDontSee` on the
         * unpadded name passes on a page that does not contain person 1 and fails on one
         * that contains person 11 — a test that reads as a paging bug and is not one.
         */
        $label = str_pad((string) $index, 2, '0', STR_PAD_LEFT);

        $user = User::factory()->create([
            'phone_e164' => '+20111'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
            'full_name' => "Waiting Person {$label}",
            'public_first_name' => "P{$label}",
            'gender' => 'woman',
            'registered_role' => 'passenger',
            'profile_status' => 'basic_complete',
        ]);

        $verification = new UserVerification;

        $verification->fill([
            'user_id' => $user->id,
            'type' => VerificationType::GovernmentId->value,
        ]);

        $verification->status = VerificationStatus::Pending->value;
        // Oldest first: the lowest number has waited longest.
        $verification->submitted_at = now()->subMinutes(1000 - $index);
        $verification->save();
    }
}

it('reaches the person who would have been cut off by a cap', function () {
    pendingVerifications(25);

    $firstPage = VerificationQueue::pending(perPage: 10);

    expect($firstPage->total())->toBe(25)
        ->and($firstPage->lastPage())->toBe(3)
        ->and($firstPage->items())->toHaveCount(10)
        // The longest wait is at the top of page one.
        ->and($firstPage->items()[0]['name'])->toBe('Waiting Person 01');

    // And the 25th is genuinely reachable — the thing a cap of any size prevents.
    // Fetched from page THREE, not by asking for page one again.
    request()->merge(['page' => 3]);

    $lastPage = VerificationQueue::pending(perPage: 10);

    request()->merge(['page' => 1]);

    expect($lastPage->items())->toHaveCount(5)
        ->and($lastPage->items()[4]['name'])->toBe('Waiting Person 25');
});

it('keeps the oldest-first order across page boundaries', function () {
    pendingVerifications(25);

    $seen = [];

    foreach ([1, 2, 3] as $pageNumber) {
        // Laravel reads the page from the request, which is how Livewire drives it too.
        request()->merge(['page' => $pageNumber]);

        foreach (VerificationQueue::pending(perPage: 10)->items() as $card) {
            $seen[] = $card['name'];
        }
    }

    request()->merge(['page' => 1]);

    expect($seen)->toHaveCount(25)
        // Nobody appears twice and nobody is skipped — the failure an unstable sort
        // produces, which is why the query tiebreaks on the id.
        ->and($seen)->toBe(array_values(array_unique($seen)))
        ->and($seen[0])->toBe('Waiting Person 01')
        ->and($seen[24])->toBe('Waiting Person 25');
});

it('announces how many are waiting in total, not how many are on screen', function () {
    pendingVerifications(25);

    actingAsAdmin($this->reviewer);

    Livewire::test(QueuePage::class)
        ->assertOk()
        // 25, not the 10 rendered. A heading that counted the page would have told a
        // reviewer with 240 people queued that 10 were waiting.
        ->assertSee(trans_choice('admin.queue.waiting', 25, ['count' => 25]));
});

it('shows paging controls only when there is more than one page', function () {
    pendingVerifications(4);

    actingAsAdmin($this->reviewer);

    Livewire::test(QueuePage::class)->assertDontSee(__('admin.pager.next'));

    pendingVerifications(20, startAt: 5);

    Livewire::test(QueuePage::class)->assertSee(__('admin.pager.next'));
});

it('turns the page without leaving the component', function () {
    pendingVerifications(25);

    actingAsAdmin($this->reviewer);

    $page = Livewire::test(QueuePage::class);

    $page->assertSee('Waiting Person 01')
        ->assertDontSee('Waiting Person 20');

    // `nextPage` is Livewire's own method — an AJAX round trip, not a navigation.
    $page->call('nextPage')
        ->assertSee('Waiting Person 11')
        ->assertDontSee('Waiting Person 01');
});

/**
 * Rows leave this queue as they are decided, so the page being read can empty under the
 * reviewer. Without stepping back they would be looking at a page past the end — a blank
 * screen that reads as "nothing waiting" while people are still queued.
 */
it('steps back a page when the last one empties', function () {
    pendingVerifications(11);

    actingAsAdmin($this->reviewer);

    $page = Livewire::test(QueuePage::class)->call('nextPage');

    // Page two holds exactly one person.
    $page->assertSee('Waiting Person 11');

    $eleventh = UserVerification::query()
        ->whereIn('user_id', User::query()
            ->where('full_name', 'Waiting Person 11')
            ->select('id'))
        ->sole();

    $page->call('approve', $eleventh->id)
        ->assertHasNoErrors()
        // Back on page one, looking at the remaining ten rather than at nothing.
        ->assertSee('Waiting Person 01');

    expect(VerificationQueue::pending()->total())->toBe(10);
});
