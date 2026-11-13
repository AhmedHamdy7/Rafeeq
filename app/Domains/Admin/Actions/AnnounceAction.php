<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AnnouncementAudience;
use App\Domains\Admin\Jobs\DeliverAnnouncement;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\Announcement;
use App\Domains\Admin\Support\AdminActionLog;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Announcements (Z01): write one now or for later, and send the ones whose time has come.
 */
final readonly class AnnounceAction
{
    /**
     * @param  array{audience: string, title_ar: string, title_en: string, body_ar: string, body_en: string, link?: ?string}  $text
     */
    public function create(AdminUser $by, array $text, ?CarbonImmutable $at = null): Announcement
    {
        if (! $by->can(AdminPermission::SettingsManage->value)) {
            throw new AuthorizationException;
        }

        $announcement = Announcement::query()->create([
            'audience' => AnnouncementAudience::from($text['audience']),
            'title_ar' => trim($text['title_ar']),
            'title_en' => trim($text['title_en']),
            'body_ar' => trim($text['body_ar']),
            'body_en' => trim($text['body_en']),
            'link' => in_array($text['link'] ?? null, Announcement::LINKS, true) ? $text['link'] : null,
            'scheduled_for' => $at !== null && $at->isFuture() ? $at : null,
            'created_by' => $by->getKey(),
        ]);

        AdminActionLog::record($by, 'announcement.create', $announcement, null, [
            'audience' => $announcement->audience->value,
            'title' => $announcement->title_en,
            'scheduled_for' => $announcement->scheduled_for?->toIso8601String(),
        ]);

        if ($announcement->scheduled_for === null) {
            $this->queue($announcement);
        }

        return $announcement->refresh();
    }

    /** A scheduled one that has not gone yet can be cancelled; it is kept, marked as never sent. */
    public function cancel(AdminUser $by, Announcement $announcement): void
    {
        if (! $by->can(AdminPermission::SettingsManage->value)) {
            throw new AuthorizationException;
        }

        if ($announcement->queued_at !== null) {
            return;
        }

        $announcement->delete();
        AdminActionLog::record($by, 'announcement.cancel', $announcement, ['title' => $announcement->title_en], null);
    }

    /** The scheduler's half: everything whose time has come. */
    public function sendDue(): int
    {
        $due = Announcement::query()->whereNull('queued_at')->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now())->get();

        $due->each(fn (Announcement $announcement) => $this->queue($announcement));

        return $due->count();
    }

    private function queue(Announcement $announcement): void
    {
        // Claimed first, so two scheduler runs cannot both queue it.
        $claimed = Announcement::query()->whereKey($announcement->id)->whereNull('queued_at')->update(['queued_at' => now()]);

        if ($claimed === 1) {
            DeliverAnnouncement::dispatch($announcement->id);
        }
    }
}
