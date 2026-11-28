<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\AppVersion\Jobs\NotifyAppUpdate;
use App\Domains\AppVersion\Models\AppRelease;
use App\Domains\AppVersion\Support\AppVersions;
use App\Domains\Identity\Models\Device;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Models\Notification;
use App\Livewire\Admin\AppVersions as AppVersionsPage;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * App versions: the release policy per store, what the app is told, the server's 426, the
 * dashboard page, and the once-per-version notification.
 */

// ---- Comparing versions ---------------------------------------------------------------------

it('compares versions as numbers, part by part', function () {
    expect(AppVersions::compare('1.10.0', '1.9.0'))->toBe(1)
        ->and(AppVersions::compare('1.2', '1.2.0'))->toBe(0)
        ->and(AppVersions::compare('2.0.0', '10.0.0'))->toBe(-1)
        ->and(AppVersions::compare('1.3.0+45', '1.3.0'))->toBe(0)
        ->and(AppVersions::normalise('v1'))->toBeNull()
        ->and(AppVersions::normalise('1.4.0-beta'))->toBe('1.4.0');
});

// ---- GET /v1/app/version --------------------------------------------------------------------

it('answers configured: false before staff set a store', function () {
    $this->getJson('/api/v1/app/version?platform=ios&version=1.0.0')
        ->assertOk()
        ->assertJsonPath('data.configured', false)
        ->assertJsonPath('data.updateRequired', false)
        ->assertJsonPath('data.updateAvailable', false)
        ->assertJsonPath('data.storeUrl', null);
});

it('tells each install whether it must update, may update, or is up to date', function (string $version, bool $required, bool $available) {
    AppRelease::factory()->create(['latest_version' => '1.4.0', 'minimum_version' => '1.2.0']);

    $this->getJson("/api/v1/app/version?platform=android&version={$version}")
        ->assertOk()
        ->assertJsonPath('data.configured', true)
        ->assertJsonPath('data.latestVersion', '1.4.0')
        ->assertJsonPath('data.minimumVersion', '1.2.0')
        ->assertJsonPath('data.updateRequired', $required)
        ->assertJsonPath('data.updateAvailable', $available)
        ->assertJsonPath('data.storeUrl', 'https://play.google.com/store/apps/details?id=com.yugo.app');
})->with([
    'below the minimum' => ['1.1.9', true, true],
    'between' => ['1.3.0', false, true],
    'on the latest' => ['1.4.0', false, false],
    'newer than the store' => ['1.5.0', false, false],
    '1.10 is not older than 1.4' => ['1.10.0', false, false],
]);

it('requires everyone below the latest to update while force update is on', function () {
    AppRelease::factory()->create(['latest_version' => '1.4.0', 'minimum_version' => '1.2.0', 'force_update' => true]);

    $this->getJson('/api/v1/app/version?platform=android&version=1.3.0')
        ->assertOk()
        ->assertJsonPath('data.forceUpdate', true)
        ->assertJsonPath('data.updateRequired', true);
});

it('returns the release notes in the language asked for', function () {
    AppRelease::factory()->ios()->create(['notes_ar' => 'بحث أسرع', 'notes_en' => 'Faster search']);

    $this->getJson('/api/v1/app/version?platform=ios', ['Accept-Language' => 'en'])->assertJsonPath('data.releaseNotes', 'Faster search');
    $this->getJson('/api/v1/app/version?platform=ios', ['Accept-Language' => 'ar'])->assertJsonPath('data.releaseNotes', 'بحث أسرع');
});

it('refuses an unknown platform', function () {
    $this->getJson('/api/v1/app/version?platform=windows')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

// ---- The server's 426 -----------------------------------------------------------------------

it('refuses every call from a version that must update, but only when the app says its version', function () {
    AppRelease::factory()->create(['latest_version' => '1.4.0', 'minimum_version' => '1.2.0']);

    $old = ['X-App-Platform' => 'android', 'X-App-Version' => '1.1.0'];

    $this->getJson('/api/v1/content/terms', $old)
        ->assertStatus(426)
        ->assertJsonPath('error.code', 'APP_UPDATE_REQUIRED');

    // The version check itself always answers, so a blocked app can find the store.
    $this->getJson('/api/v1/app/version?platform=android&version=1.1.0', $old)->assertOk();

    // A supported version, no headers, or the other store's headers: never refused.
    $this->getJson('/api/v1/content/terms', ['X-App-Platform' => 'android', 'X-App-Version' => '1.3.0'])->assertStatus(404);
    $this->getJson('/api/v1/content/terms')->assertStatus(404);
    $this->getJson('/api/v1/content/terms', ['X-App-Platform' => 'ios', 'X-App-Version' => '1.1.0'])->assertStatus(404);
});

// ---- The dashboard page ---------------------------------------------------------------------

it('saves a store, audits the change with its reason, and applies it at once', function () {
    $admin = actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(AppVersionsPage::class)
        ->set('forms.ios.latest', '2.0.0')
        ->set('forms.ios.minimum', '1.8.0')
        ->set('forms.ios.store_url', 'https://apps.apple.com/app/id123')
        ->set('forms.ios.force', true)
        ->set('forms.ios.reason', 'Release 2.0 with the new trip screen.')
        ->call('save', 'ios')
        ->assertHasNoErrors();

    $release = AppRelease::query()->findOrFail('ios');

    expect($release->latest_version)->toBe('2.0.0')
        ->and($release->force_update)->toBeTrue()
        ->and($release->updated_by)->toBe($admin->id)
        ->and(AdminAction::query()->where('action', 'app_version.update')->sole()->reason)->toBe('Release 2.0 with the new trip screen.');

    $this->getJson('/api/v1/app/version?platform=ios&version=1.9.0')->assertJsonPath('data.updateRequired', true);
});

it('refuses a minimum newer than the latest, a bad version and a non-https link', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(AppVersionsPage::class)
        ->set('forms.android.latest', '1.2.0')
        ->set('forms.android.minimum', '1.3.0')
        ->set('forms.android.store_url', 'https://play.google.com/store/apps/details?id=x')
        ->set('forms.android.reason', 'Trying a wrong pair of versions.')
        ->call('save', 'android')
        ->assertHasErrors('forms.android.minimum')
        ->set('forms.android.minimum', 'one')
        ->call('save', 'android')
        ->assertHasErrors('forms.android.minimum')
        ->set('forms.android.minimum', '1.0.0')
        ->set('forms.android.store_url', 'http://example.com')
        ->call('save', 'android')
        ->assertHasErrors('forms.android.store_url');

    expect(AppRelease::query()->count())->toBe(0);
});

it('queues the update notification once per version', function () {
    Queue::fake();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));
    AppRelease::factory()->create(['latest_version' => '1.4.0', 'notified_version' => '1.4.0']);

    $page = Livewire::test(AppVersionsPage::class)
        ->set('forms.android.notify', true)
        ->set('forms.android.reason', 'Announcing the same version again.')
        ->call('save', 'android')
        ->assertHasNoErrors();

    Queue::assertNothingPushed();

    $page->set('forms.android.latest', '1.5.0')
        ->set('forms.android.notify', true)
        ->set('forms.android.reason', 'Release 1.5 is live on Google Play.')
        ->call('save', 'android');

    Queue::assertPushed(NotifyAppUpdate::class, fn ($job) => $job->platform === 'android' && $job->version === '1.5.0');
});

it('is closed to everybody without settings.manage', function (AdminRole $role) {
    actingAsAdmin(adminWithRole($role));

    Livewire::test(AppVersionsPage::class)->assertForbidden();
})->with([AdminRole::Operations, AdminRole::SafetyLead, AdminRole::Verification, AdminRole::Finance, AdminRole::Support]);

// ---- Who the notification reaches ------------------------------------------------------------

it('notifies only members on an older version of that store, and only once', function () {
    AppRelease::factory()->create(['latest_version' => '1.5.0']);

    $old = Device::factory()->create(['platform' => 'android', 'app_version' => '1.4.0']);
    $unknown = Device::factory()->create(['platform' => 'android', 'app_version' => null]);
    $current = Device::factory()->create(['platform' => 'android', 'app_version' => '1.5.0']);
    $newer = Device::factory()->create(['platform' => 'android', 'app_version' => '1.10.0']);
    $iphone = Device::factory()->create(['platform' => 'ios', 'app_version' => '1.0.0']);
    $revoked = Device::factory()->create(['platform' => 'android', 'app_version' => '1.0.0', 'revoked_at' => now()]);

    (new NotifyAppUpdate('android', '1.5.0'))->handle();
    (new NotifyAppUpdate('android', '1.5.0'))->handle();

    $told = Notification::query()->where('type', NotificationType::AppUpdate->value)->pluck('user_id')->unique()->sort()->values()->all();

    expect($told)->toBe(collect([$old->user_id, $unknown->user_id])->sort()->values()->all());

    $release = AppRelease::query()->findOrFail('android');

    expect($release->notified_version)->toBe('1.5.0')
        ->and($release->notified_count)->toBe(2)
        ->and(in_array($current->user_id, $told, true) || in_array($newer->user_id, $told, true)
            || in_array($iphone->user_id, $told, true) || in_array($revoked->user_id, $told, true))->toBeFalse();
});
