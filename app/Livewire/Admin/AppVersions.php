<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\AppVersion\Enums\AppPlatform;
use App\Domains\AppVersion\Jobs\NotifyAppUpdate;
use App\Domains\AppVersion\Models\AppRelease;
use App\Domains\AppVersion\Support\AppVersions as Versions;
use App\Domains\Identity\Models\Device;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * APP VERSIONS: per store, the latest version, the oldest still allowed, the store link and two
 * switches — force update, and tell users about this version.
 *
 * `settings.manage` (super admin), like the other platform settings. Every save is audited with
 * its reason and the values before and after.
 *
 * "Notify users" sends once per version, and only to members whose phone on that store is older
 * (NotifyAppUpdate). Saving again with the switch on for a version already announced sends
 * nothing — the page says so.
 */
#[Layout('components.layouts.admin')]
class AppVersions extends Component
{
    /** @var array<string, array<string, mixed>> one form per platform, keyed ios / android */
    public array $forms = [];

    public function mount(): void
    {
        $this->authorizeTo();

        foreach (AppPlatform::cases() as $platform) {
            $this->loadForm($platform);
        }
    }

    public function save(string $platform): void
    {
        $this->authorizeTo();

        $store = AppPlatform::tryFrom($platform) ?? abort(404);
        $key = "forms.{$store->value}";

        $this->validate([
            "{$key}.latest" => ['required', 'string', 'regex:'.Versions::PATTERN],
            "{$key}.minimum" => ['required', 'string', 'regex:'.Versions::PATTERN],
            "{$key}.store_url" => ['required', 'url:https', 'max:500'],
            "{$key}.notes_ar" => ['nullable', 'string', 'max:1000'],
            "{$key}.notes_en" => ['nullable', 'string', 'max:1000'],
            "{$key}.reason" => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: [
            "{$key}.latest" => __('admin/app_versions.latest'),
            "{$key}.minimum" => __('admin/app_versions.minimum'),
            "{$key}.store_url" => __('admin/app_versions.store_url'),
            "{$key}.reason" => __('admin/app_versions.reason'),
        ]);

        $form = $this->forms[$store->value];

        // The one rule a regex cannot carry: the oldest allowed cannot be newer than the latest.
        if (Versions::compare($form['minimum'], $form['latest']) > 0) {
            $this->addError("{$key}.minimum", __('admin/app_versions.minimum_above_latest'));

            return;
        }

        $admin = $this->admin();

        $release = DB::transaction(function () use ($store, $form, $admin): array {
            $release = AppRelease::query()->lockForUpdate()->find($store->value) ?? new AppRelease(['platform' => $store]);
            $before = $release->exists ? self::snapshot($release) : null;

            $release->fill([
                'latest_version' => trim($form['latest']),
                'minimum_version' => trim($form['minimum']),
                'store_url' => trim($form['store_url']),
                'force_update' => (bool) $form['force'],
                'notes_ar' => trim((string) $form['notes_ar']) ?: null,
                'notes_en' => trim((string) $form['notes_en']) ?: null,
            ]);
            $release->updated_by = $admin->id;
            $release->save();

            AdminActionLog::record($admin, 'app_version.update', $release, $before, self::snapshot($release), $form['reason']);

            return [$release, $before];
        });

        [$saved] = $release;
        Versions::forget($store);

        $message = __('admin/app_versions.saved', ['platform' => __('admin/app_versions.platforms.'.$store->value)]);

        if ($form['notify']) {
            if ($saved->notified_version === $saved->latest_version) {
                $message .= ' '.__('admin/app_versions.already_notified', ['version' => $saved->latest_version]);
            } else {
                NotifyAppUpdate::dispatch($store->value, $saved->latest_version);
                $message .= ' '.__('admin/app_versions.notifying', ['version' => $saved->latest_version]);
            }
        }

        session()->flash('status', $message);
        $this->loadForm($store);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(AppRelease $release): array
    {
        return [
            'latest_version' => $release->latest_version,
            'minimum_version' => $release->minimum_version,
            'store_url' => $release->store_url,
            'force_update' => $release->force_update,
        ];
    }

    // Not `fill()`: Livewire's Component already has a public one.
    private function loadForm(AppPlatform $platform): void
    {
        $release = AppRelease::query()->find($platform->value);

        $this->forms[$platform->value] = [
            'latest' => (string) $release?->latest_version,
            'minimum' => (string) $release?->minimum_version,
            'store_url' => (string) $release?->store_url,
            'force' => (bool) $release?->force_update,
            'notes_ar' => (string) $release?->notes_ar,
            'notes_en' => (string) $release?->notes_en,
            'notify' => false,
            'reason' => '',
        ];
    }

    /**
     * How the active phones on a store are spread over versions, against its latest release.
     *
     * @return array{total: int, latest: int, older: int, unknown: int}
     */
    private static function spread(AppPlatform $platform, ?AppRelease $release): array
    {
        $rows = Device::query()
            ->where('platform', $platform->value)
            ->whereNull('revoked_at')
            ->selectRaw('app_version, COUNT(*) as n')
            ->groupBy('app_version')
            ->get();

        $spread = ['total' => 0, 'latest' => 0, 'older' => 0, 'unknown' => 0];

        foreach ($rows as $row) {
            $count = (int) $row->n;
            $version = Versions::normalise($row->app_version);
            $spread['total'] += $count;

            match (true) {
                $version === null => $spread['unknown'] += $count,
                $release !== null && Versions::compare($version, $release->latest_version) < 0 => $spread['older'] += $count,
                default => $spread['latest'] += $count,
            };
        }

        return $spread;
    }

    private function admin(): ?AdminUser
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser ? $admin : null;
    }

    private function authorizeTo(): void
    {
        abort_unless($this->admin()?->can(AdminPermission::SettingsManage->value) === true, 403);
    }

    public function render()
    {
        $releases = AppRelease::query()->with('updatedBy')->get()->keyBy(fn (AppRelease $r) => $r->platform->value);

        $platforms = [];

        foreach (AppPlatform::cases() as $platform) {
            $release = $releases->get($platform->value);

            $platforms[$platform->value] = [
                'release' => $release,
                'spread' => self::spread($platform, $release),
            ];
        }

        return view('livewire.admin.app-versions', ['platforms' => $platforms])
            ->title(__('admin/app_versions.title'));
    }
}
