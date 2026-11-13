<?php

namespace App\Domains\Admin\Models;

use App\Domains\Admin\Enums\AnnouncementAudience;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An announcement to members (Z01). Lands in the app's inbox only, never as a push
 * (NOTIF-02), so it can never wake anybody up for a road-works notice.
 */
#[Fillable(['audience', 'title_ar', 'title_en', 'body_ar', 'body_en', 'link', 'scheduled_for', 'created_by'])]
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUlids;

    /** The screens an announcement may open in the app. */
    public const array LINKS = ['home', 'driver_home', 'trips', 'payments', 'profile', 'help'];

    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'scheduled_for' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'reach' => 'integer',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    public function isSent(): bool
    {
        return $this->sent_at !== null;
    }
}
