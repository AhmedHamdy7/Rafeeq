<?php

use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\Announcement;
use App\Domains\Admin\Models\ContentPage;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\Notification;
use App\Livewire\Admin\Announcements;
use App\Livewire\Admin\Content;
use App\Livewire\Admin\MemberProfile;
use Livewire\Livewire;

/**
 * Announcements (Z01–Z04), Content (J01) and messaging one member (D19).
 */
function inboxType(Notification $notification): string
{
    return $notification->type instanceof BackedEnum ? $notification->type->value : (string) $notification->type;
}

it('puts an announcement in every inbox of its audience, in each member’s language, never as a push', function () {
    $arabic = User::factory()->create(['preferred_language' => 'ar']);
    $english = User::factory()->create(['preferred_language' => 'en']);
    $driver = DriverProfile::factory()->create(['status' => DriverProfileStatus::Approved])->user;
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Announcements::class)
        ->call('pick', 'compose')
        ->set('audience', 'passengers')
        ->set('titleAr', 'أعمال طرق')
        ->set('titleEn', 'Road works')
        ->set('bodyAr', 'اخرج بدري 10 دقايق')
        ->set('bodyEn', 'Leave 10 minutes early')
        ->set('link', 'trips')
        ->call('send')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/communication.sent_done'));

    $rows = Notification::query()->get()->filter(fn ($n) => inboxType($n) === 'announcement');

    expect($rows->firstWhere('user_id', $arabic->id)?->title)->toBe('أعمال طرق')
        ->and($rows->firstWhere('user_id', $english->id)?->title)->toBe('Road works')
        ->and($rows->firstWhere('user_id', $driver->id))->toBeNull()
        ->and($rows->every(fn ($n) => ($n->channel instanceof BackedEnum ? $n->channel->value : $n->channel) === NotificationChannel::InApp->value))->toBeTrue()
        ->and(Announcement::query()->sole()->sent_at)->not->toBeNull()
        ->and(AdminAction::query()->where('action', 'announcement.create')->exists())->toBeTrue();
});

it('holds a scheduled announcement until its time, then sends it once', function () {
    User::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Announcements::class)
        ->call('pick', 'compose')
        ->set('titleAr', 'خط جديد')->set('titleEn', 'New corridor')
        ->set('bodyAr', 'من 6 أكتوبر')->set('bodyEn', 'From 6th of October')
        ->set('scheduleAt', now('Africa/Cairo')->addHour()->format('Y-m-d\TH:i'))
        ->call('send')
        ->assertHasNoErrors();

    $announcement = Announcement::query()->sole();
    expect($announcement->sent_at)->toBeNull();

    $this->artisan('announcements:send-due')->assertExitCode(0);
    expect($announcement->refresh()->queued_at)->toBeNull();

    $this->travel(2)->hours();
    $this->artisan('announcements:send-due')->assertExitCode(0);
    $this->artisan('announcements:send-due')->assertExitCode(0);

    expect($announcement->refresh()->sent_at)->not->toBeNull()
        ->and($announcement->reach)->toBe(1);
});

it('lets Customer Service read the sent list but not write', function () {
    Announcement::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Announcements::class)
        ->assertOk()
        ->assertSee('Ring road works')
        ->call('pick', 'compose')
        ->assertSet('tab', 'sent')
        ->call('send')
        ->assertForbidden();
});

it('saves content as a new version each time, with the old text in the audit log', function () {
    actingAsAdmin(adminWithRole(AdminRole::SuperAdmin));

    Livewire::test(Content::class)
        ->call('edit', 'support_contact')->set('bodyAr', 'واتساب')->set('bodyEn', 'WhatsApp')->call('save')->assertHasNoErrors()
        ->call('edit', 'support_contact')->set('bodyAr', 'واتساب 6–22')->set('bodyEn', 'WhatsApp 06:00–22:00')->call('save')->assertHasNoErrors();

    $page = ContentPage::query()->findOrFail('support_contact');

    expect($page->version)->toBe(2)
        ->and($page->body_en)->toBe('WhatsApp 06:00–22:00')
        ->and(AdminAction::query()->where('action', 'content.update')->latest('created_at')->latest('id')->first()->old_value['body_en'])->toBe('WhatsApp');
});

it('keeps Content to Admins', function () {
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(Content::class)->assertForbidden();
});

it('messages one member in their inbox only, and keeps the text in the audit log', function () {
    $member = User::factory()->create();
    actingAsAdmin(adminWithRole(AdminRole::Support));

    Livewire::test(MemberProfile::class, ['user' => $member])
        ->call('startAction', 'message')
        ->set('messageTitle', 'About your payment')
        ->set('reason', 'We received it, thank you.')
        ->call('runAction')
        ->assertHasNoErrors()
        ->assertSet('done', __('admin/actions.done.message'));

    $rows = Notification::query()->where('user_id', $member->id)->get();

    expect($rows)->toHaveCount(1)
        ->and(inboxType($rows->first()))->toBe('staff_message')
        ->and($rows->first()->body)->toBe('We received it, thank you.')
        ->and(AdminAction::query()->where('action', 'member.message')->sole()->new_value['body'])->toBe('We received it, thank you.');
});

it('has every communication string in both languages', function () {
    expect(array_keys(trans('admin/communication', [], 'ar')))->toBe(array_keys(trans('admin/communication', [], 'en')));
});
