<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\AnnounceAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AnnouncementAudience;
use App\Domains\Admin\Jobs\DeliverAnnouncement;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\Announcement;
use App\Domains\Notification\Enums\NotificationChannel;
use App\Domains\Notification\Models\Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * ANNOUNCEMENTS AND DELIVERY LOG (Z01, Z02, Z04).
 *
 * Admins write announcements in Arabic and English, now or for later; they land in the app's
 * inbox only, never as a push (NOTIF-02). Customer Service read the sent list (Z04). The
 * delivery log lists the pushes that failed, with why, so "I never got it" has an answer.
 */
#[Layout('components.layouts.admin')]
class Announcements extends Component
{
    use WithPagination;

    public const array TABS = ['compose', 'sent', 'failed'];

    #[Url(as: 'tab')]
    public string $tab = 'sent';

    public string $audience = 'everyone';

    public string $titleAr = '';

    public string $titleEn = '';

    public string $bodyAr = '';

    public string $bodyEn = '';

    public string $link = '';

    public string $scheduleAt = '';

    public ?string $done = null;

    public function mount(): void
    {
        abort_unless($this->may(AdminPermission::SettingsManage) || $this->may(AdminPermission::TicketView), 403);

        $this->tab = in_array($this->tab, $this->tabs(), true) ? $this->tab : 'sent';
    }

    public function pick(string $tab): void
    {
        $this->tab = in_array($tab, $this->tabs(), true) ? $tab : 'sent';
        $this->done = null;
        $this->resetPage();
    }

    public function send(AnnounceAction $announcements): void
    {
        abort_unless($this->may(AdminPermission::SettingsManage), 403);

        $this->validate([
            'audience' => ['required', 'string', Rule::enum(AnnouncementAudience::class)],
            'titleAr' => ['required', 'string', 'max:120'],
            'titleEn' => ['required', 'string', 'max:120'],
            'bodyAr' => ['required', 'string', 'max:1000'],
            'bodyEn' => ['required', 'string', 'max:1000'],
            'link' => ['nullable', 'string', Rule::in(Announcement::LINKS)],
            'scheduleAt' => ['nullable', 'date', 'after:now'],
        ], attributes: [
            'titleAr' => __('admin/communication.title_ar'),
            'titleEn' => __('admin/communication.title_en'),
            'bodyAr' => __('admin/communication.body_ar'),
            'bodyEn' => __('admin/communication.body_en'),
            'scheduleAt' => __('admin/communication.schedule'),
        ]);

        $at = $this->scheduleAt !== '' ? CarbonImmutable::parse($this->scheduleAt, 'Africa/Cairo') : null;

        $announcement = $announcements->create($this->admin(), [
            'audience' => $this->audience,
            'title_ar' => $this->titleAr,
            'title_en' => $this->titleEn,
            'body_ar' => $this->bodyAr,
            'body_en' => $this->bodyEn,
            'link' => $this->link !== '' ? $this->link : null,
        ], $at);

        $this->reset('titleAr', 'titleEn', 'bodyAr', 'bodyEn', 'link', 'scheduleAt');
        $this->audience = 'everyone';
        $this->done = $announcement->scheduled_for !== null
            ? __('admin/communication.scheduled_done', ['at' => $announcement->scheduled_for->timezone('Africa/Cairo')->format('D j M H:i')])
            : __('admin/communication.sent_done');
        $this->tab = 'sent';
    }

    public function cancel(string $id, AnnounceAction $announcements): void
    {
        abort_unless($this->may(AdminPermission::SettingsManage), 403);

        $announcements->cancel($this->admin(), Announcement::query()->whereKey($id)->firstOrFail());
    }

    /** @return list<string> */
    private function tabs(): array
    {
        return $this->may(AdminPermission::SettingsManage) ? self::TABS : ['sent'];
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    private function may(AdminPermission $permission): bool
    {
        return Auth::guard('admin')->user()?->can($permission->value) === true;
    }

    public function render()
    {
        $reach = in_array($this->audience, array_column(AnnouncementAudience::cases(), 'value'), true)
            ? DeliverAnnouncement::audience(AnnouncementAudience::from($this->audience))->count()
            : 0;

        return view('livewire.admin.announcements', [
            'tabs' => $this->tabs(),
            'mayWrite' => $this->may(AdminPermission::SettingsManage),
            'reach' => $this->tab === 'compose' ? $reach : null,
            'sent' => $this->tab === 'sent'
                ? Announcement::query()->with('author')->orderByRaw('sent_at is null desc')->latest('created_at')->paginate(20)
                : null,
            'failed' => $this->tab === 'failed'
                ? Notification::query()->with('user')->where('channel', NotificationChannel::Push->value)->whereNotNull('failed_reason')->latest('created_at')->paginate(25)
                : null,
            'audiences' => AnnouncementAudience::cases(),
            'links' => Announcement::LINKS,
        ])->title(__('admin/communication.title'));
    }
}
