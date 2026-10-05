<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\UpdatePlatformSettingAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Admin\Support\SettingsCatalogue;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The SETTINGS section: the platform's tunable numbers, grouped, with their defaults.
 *
 * `settings.manage` for everything, read and write — only the super admin holds it. The
 * numbers are not secret, but a page that shows them is mostly an invitation to change them,
 * and every one of them reaches every member.
 *
 * One setting is edited at a time, with a reason, and the page shows the bounds before the
 * value is typed so a refusal is never a surprise.
 */
#[Layout('components.layouts.admin')]
class Settings extends Component
{
    public ?string $editing = null;

    /** 'update' or 'reset'. */
    public string $mode = '';

    public string $value = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorizeTo();
    }

    public function edit(string $key, string $mode = 'update'): void
    {
        $this->authorizeTo();

        $this->resetValidation();
        $this->editing = SettingsCatalogue::entry($key) === null ? null : $key;
        $this->mode = $mode === 'reset' ? 'reset' : 'update';
        $this->value = $this->editing === null ? '' : (string) PlatformSetting::value($key, SettingsCatalogue::defaultFor($key));
        $this->reason = '';
    }

    public function cancel(): void
    {
        $this->resetValidation();
        $this->reset('editing', 'mode', 'value', 'reason');
    }

    public function save(UpdatePlatformSettingAction $action): void
    {
        $this->authorizeTo();

        $entry = SettingsCatalogue::entry((string) $this->editing) ?? abort(404);

        $rules = ['reason' => ['required', 'string', 'min:10', 'max:255']];

        if ($this->mode === 'update') {
            $rules['value'] = ['required', 'integer', 'min:'.$entry['min'], 'max:'.$entry['max']];
        }

        $this->validate($rules, attributes: [
            'value' => __('admin.settings.value'),
            'reason' => __('admin.settings.reason'),
        ]);

        try {
            $this->mode === 'reset'
                ? $action->reset($this->editing, $this->admin(), $this->reason)
                : $action->update($this->editing, (int) $this->value, $this->admin(), $this->reason);
        } catch (DomainException $e) {
            $this->addError('value', $e->fields['value'][0] ?? __('errors.'.$e->errorCode->value));

            return;
        }

        session()->flash('status', __('admin.settings.saved', ['name' => __(SettingsCatalogue::labelKey($this->editing))]));

        $this->cancel();
    }

    private function admin(): ?AdminUser
    {
        return Auth::guard('admin')->user();
    }

    private function authorizeTo(): void
    {
        abort_unless($this->admin()?->can(AdminPermission::SettingsManage->value) === true, 403);
    }

    public function render()
    {
        $overrides = PlatformSetting::query()
            ->whereIn('setting_key', array_keys(SettingsCatalogue::ENTRIES))
            ->with('updatedBy')
            ->get()
            ->keyBy('setting_key');

        $groups = [];

        foreach (SettingsCatalogue::ENTRIES as $key => $entry) {
            $override = $overrides->get($key);

            $groups[$entry['group']][] = [
                'key' => $key,
                'entry' => $entry,
                'default' => SettingsCatalogue::defaultFor($key),
                'current' => $override?->setting_value ?? SettingsCatalogue::defaultFor($key),
                'override' => $override,
            ];
        }

        return view('livewire.admin.settings', [
            'groups' => $groups,
        ])->title(__('admin.settings.title'));
    }
}
