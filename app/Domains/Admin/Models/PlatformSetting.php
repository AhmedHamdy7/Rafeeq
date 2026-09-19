<?php

namespace App\Domains\Admin\Models;

use Database\Factories\PlatformSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Every configurable number in the system (Bible §4/§7.3) — cached
 * indefinitely and invalidated on write (pitfall #50: don't re-query this
 * on every request).
 */
#[Fillable(['setting_key', 'setting_value', 'value_type', 'description'])]
class PlatformSetting extends Model
{
    /** @use HasFactory<PlatformSettingFactory> */
    use HasFactory;

    protected $primaryKey = 'setting_key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'setting_value' => 'array',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::saved(fn (self $setting) => Cache::forget("platform_settings.{$setting->setting_key}"));
        static::deleted(fn (self $setting) => Cache::forget("platform_settings.{$setting->setting_key}"));
    }

    /**
     * The caller's `$default` is applied AFTER the cache, never inside it:
     * caching it would freeze the first caller's fallback forever and hand
     * it to every later caller that passed a different one — and would keep
     * masking the row once it finally exists.
     */
    public static function value(string $key, mixed $default = null): mixed
    {
        $stored = Cache::rememberForever(
            "platform_settings.{$key}",
            fn () => static::find($key)?->setting_value,
        );

        return $stored ?? $default;
    }
}
