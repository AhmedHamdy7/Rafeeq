<?php

namespace App\Domains\AppVersion\Models;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\AppVersion\Enums\AppPlatform;
use Database\Factories\AppReleaseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One store's release policy: the latest version, the oldest still allowed, the store link and
 * the force-update switch. Keyed by platform — there is exactly one row per store.
 */
#[Fillable(['platform', 'latest_version', 'minimum_version', 'store_url', 'force_update', 'notes_ar', 'notes_en'])]
class AppRelease extends Model
{
    /** @use HasFactory<AppReleaseFactory> */
    use HasFactory;

    protected $primaryKey = 'platform';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'platform' => AppPlatform::class,
            'force_update' => 'boolean',
            'notified_at' => 'datetime',
            'notified_count' => 'integer',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }
}
