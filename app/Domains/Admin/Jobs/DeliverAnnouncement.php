<?php

namespace App\Domains\Admin\Jobs;

use App\Domains\Admin\Enums\AnnouncementAudience;
use App\Domains\Admin\Models\Announcement;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Identity\Models\User;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Puts an announcement in every inbox of its audience (Z01), in each member's own language.
 *
 * 🔒 `quiet`: inbox only, never a push (NOTIF-02). Runs once — `sent_at` is set at the end and
 * checked at the start, so a retried job does not post the same notice twice to the members
 * it already reached… except those of an interrupted run, which is the lesser harm.
 */
final class DeliverAnnouncement implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly string $announcementId) {}

    public function handle(): void
    {
        $announcement = Announcement::query()->whereKey($this->announcementId)->first();

        if ($announcement === null || $announcement->sent_at !== null) {
            return;
        }

        $reach = 0;

        self::audience($announcement->audience)->chunkById(500, function ($users) use ($announcement, &$reach): void {
            foreach ($users as $user) {
                $arabic = ($user->preferred_language ?: config('app.locale')) === 'ar';

                Notifier::send($user, NotificationType::Announcement, params: [
                    'title' => $arabic ? $announcement->title_ar : $announcement->title_en,
                    'body' => $arabic ? $announcement->body_ar : $announcement->body_en,
                ], data: array_filter([
                    'announcementId' => (string) $announcement->id,
                    'link' => (string) $announcement->link,
                ]), quiet: true);

                $reach++;
            }
        });

        $announcement->forceFill(['sent_at' => now(), 'reach' => $reach])->save();
    }

    /**
     * Who an audience is. Drivers are approved drivers; passengers are everybody else (every
     * member can ride, but a notice "for passengers" is not meant for the people driving them).
     *
     * @return Builder<User>
     */
    public static function audience(AnnouncementAudience $audience): Builder
    {
        $drivers = fn (Builder $query) => $query->where('status', DriverProfileStatus::Approved->value);

        return match ($audience) {
            AnnouncementAudience::Everyone => User::query(),
            AnnouncementAudience::Drivers => User::query()->whereHas('driverProfile', $drivers),
            AnnouncementAudience::Passengers => User::query()->whereDoesntHave('driverProfile', $drivers),
        };
    }
}
