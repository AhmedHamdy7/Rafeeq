<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Admin\Support\SettingsCatalogue;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Changing one of the platform's tunable numbers from the dashboard (Chapter 12, SETTINGS).
 *
 * This is the half of binding standard #11 ("no magic number in the code — every number in
 * platform_settings") that was never built: every settings class already read
 * `platform_settings` first, and nothing but a console could write it.
 *
 * Every change and every reset is audited with a reason and the value before and after,
 * because these numbers move what members experience — how long an OTP lives, how long a
 * driver waits, how long a GPS trail is kept — and "who changed the wait time, and why" is
 * a question somebody will ask.
 *
 * The page validates too; this re-validates, because the catalogue's bounds are the rule
 * and a Livewire action is a request the browser can shape however it likes.
 */
final readonly class UpdatePlatformSettingAction
{
    /**
     * Pairs that must stay in order, checked against the value the OTHER key will have.
     * A minimum price above the maximum would make every commute unpublishable.
     */
    private const array ORDERED_PAIRS = [
        ['commute.min_price_piastres', 'commute.max_price_piastres'],
        ['profile.full_name_min_length', 'profile.full_name_max_length'],
    ];

    public function update(string $key, int $value, AdminUser $admin, string $reason): void
    {
        $entry = $this->editable($key);

        if ($value < $entry['min'] || $value > $entry['max']) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'value' => [__('admin.settings.out_of_range', ['min' => $entry['min'], 'max' => $entry['max']])],
            ]);
        }

        $this->assertStaysInOrder($key, $value);

        DB::transaction(function () use ($key, $value, $admin, $reason): void {
            $before = $this->current($key);

            $setting = PlatformSetting::query()->find($key) ?? new PlatformSetting(['setting_key' => $key]);
            $setting->setting_value = $value;
            $setting->value_type = 'integer';
            $setting->updated_by = $admin->id;
            $setting->save();

            AdminActionLog::record($admin, 'settings.update', $setting,
                before: ['value' => $before],
                after: ['value' => $value],
                reason: $reason,
            );
        });
    }

    /**
     * Back to the shipped default: the row is removed, so the code reads `config/rafeeq.php`
     * again — and a later change to that default reaches this instance, which an override
     * that merely equals today's default would not.
     */
    public function reset(string $key, AdminUser $admin, string $reason): void
    {
        $this->editable($key);

        $setting = PlatformSetting::query()->find($key);

        if ($setting === null) {
            return;
        }

        $this->assertStaysInOrder($key, (int) SettingsCatalogue::defaultFor($key));

        DB::transaction(function () use ($setting, $key, $admin, $reason): void {
            $before = $setting->setting_value;

            $setting->delete();

            AdminActionLog::record($admin, 'settings.reset', $setting,
                before: ['value' => $before],
                after: ['value' => SettingsCatalogue::defaultFor($key)],
                reason: $reason,
            );
        });
    }

    /**
     * @return array{group: string, min: int, max: int, unit: string, locked?: string}
     */
    private function editable(string $key): array
    {
        $entry = SettingsCatalogue::entry($key);

        // Not in the catalogue: a key nothing reads, or one somebody typed. Either way, no.
        if ($entry === null) {
            throw DomainException::of(ErrorCode::NotFound);
        }

        if (isset($entry['locked'])) {
            throw DomainException::of(ErrorCode::Forbidden);
        }

        return $entry;
    }

    private function assertStaysInOrder(string $key, int $value): void
    {
        foreach (self::ORDERED_PAIRS as [$low, $high]) {
            $ordered = match ($key) {
                $low => $value < (int) $this->current($high),
                $high => (int) $this->current($low) < $value,
                default => true,
            };

            if (! $ordered) {
                throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                    'value' => [__('admin.settings.out_of_order', [
                        'low' => $low,
                        'high' => $high,
                    ])],
                ]);
            }
        }
    }

    private function current(string $key): mixed
    {
        return PlatformSetting::value($key, SettingsCatalogue::defaultFor($key));
    }
}
