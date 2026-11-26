<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\UpdatePlatformSettingAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\PlatformSetting;
use App\Domains\Admin\Support\SettingsCatalogue;
use App\Domains\Admin\Support\SettingsLadders;
use App\Domains\Shared\Exceptions\DomainException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
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

    /** F04 · the setting whose change history is open. */
    public ?string $historyFor = null;

    /** 'update' or 'reset'. */
    public string $mode = '';

    public string $value = '';

    public string $reason = '';

    /** F02 · the fee ladder being edited, and its rungs (rung label => percent). */
    public ?string $ladder = null;

    /** @var array<string, string> */
    public array $ladderValues = [];

    /** The section on screen (one of SettingsCatalogue::GROUPS); empty means the first. */
    #[Url(as: 'group', except: '')]
    public string $group = '';

    /** Searches every section at once, by label (in the current language) or by key. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    public bool $changedOnly = false;

    public function mount(): void
    {
        $this->authorizeTo();
    }

    public function showGroup(string $group): void
    {
        if (! in_array($group, SettingsCatalogue::GROUPS, true)) {
            return;
        }

        $this->cancel();
        $this->cancelLadder();
        $this->reset('historyFor', 'search');
        $this->group = $group;
    }

    // Opening a setting's history from anywhere brings its section on screen with it.
    public function updatedHistoryFor(?string $key): void
    {
        $this->group = self::groupOf((string) $key) ?? $this->group;
    }

    private static function groupOf(string $key): ?string
    {
        return (SettingsCatalogue::entry($key) ?? SettingsCatalogue::textEntry($key))['group'] ?? null;
    }

    public function edit(string $key, string $mode = 'update'): void
    {
        $this->authorizeTo();

        $this->resetValidation();
        $isText = SettingsCatalogue::textEntry($key) !== null;
        $this->editing = SettingsCatalogue::entry($key) === null && ! $isText ? null : $key;
        $this->mode = $mode === 'reset' ? 'reset' : 'update';
        $this->value = match (true) {
            $this->editing === null => '',
            $isText => (string) SettingsCatalogue::text($key),
            default => (string) PlatformSetting::value($key, SettingsCatalogue::defaultFor($key)),
        };
        $this->reason = '';

        if ($this->editing !== null && $this->search === '') {
            $this->group = self::groupOf($this->editing) ?? $this->group;
        }
    }

    public function cancel(): void
    {
        $this->resetValidation();
        $this->reset('editing', 'mode', 'value', 'reason');
    }

    public function save(UpdatePlatformSettingAction $action): void
    {
        $this->authorizeTo();

        $text = SettingsCatalogue::textEntry((string) $this->editing);
        $entry = $text ?? SettingsCatalogue::entry((string) $this->editing) ?? abort(404);

        $rules = ['reason' => ['required', 'string', 'min:10', 'max:255']];

        if ($this->mode === 'update') {
            $rules['value'] = $text === null
                ? ['required', 'integer', 'min:'.$entry['min'], 'max:'.$entry['max']]
                : array_values(array_filter(['required', 'string', 'max:'.$text['max'], match ($text['format'] ?? null) {
                    'url' => 'url:https',
                    'email' => 'email',
                    default => null,
                }]));
        }

        $this->validate($rules, attributes: [
            'value' => __('admin.settings.value'),
            'reason' => __('admin.settings.reason'),
        ]);

        try {
            match (true) {
                $this->mode === 'reset' => $action->reset($this->editing, $this->admin(), $this->reason),
                $text !== null => $action->updateText($this->editing, trim($this->value), $this->admin(), $this->reason),
                default => $action->update($this->editing, (int) $this->value, $this->admin(), $this->reason),
            };
        } catch (DomainException $e) {
            $this->addError('value', $e->fields['value'][0] ?? __('errors.'.$e->errorCode->value));

            return;
        }

        session()->flash('status', __('admin.settings.saved', ['name' => __(SettingsCatalogue::labelKey($this->editing))]));

        $this->cancel();
    }

    public function editLadder(string $name): void
    {
        $this->authorizeTo();
        $this->cancel();

        $rungs = SettingsLadders::ladder($name) ?? abort(404);

        $this->ladder = $name;
        $this->group = 'fees';
        $this->ladderValues = array_map(fn (string $key) => (string) PlatformSetting::value($key, SettingsCatalogue::defaultFor($key)), $rungs);
    }

    public function cancelLadder(): void
    {
        $this->resetValidation();
        $this->reset('ladder', 'ladderValues', 'reason');
    }

    public function saveLadder(UpdatePlatformSettingAction $action): void
    {
        $this->authorizeTo();

        $rungs = SettingsLadders::ladder((string) $this->ladder) ?? abort(404);

        $rules = ['reason' => ['required', 'string', 'min:10', 'max:255']];

        foreach ($rungs as $rung => $key) {
            $entry = SettingsCatalogue::entry($key);
            $rules["ladderValues.{$rung}"] = ['required', 'integer', 'min:'.$entry['min'], 'max:'.$entry['max']];
        }

        $this->validate($rules, attributes: ['reason' => __('admin.settings.reason')] + collect($rungs)->mapWithKeys(fn ($key, $rung) => ["ladderValues.{$rung}" => __('admin.settings.ladders.rung', ['rung' => $rung])])->all());

        try {
            $action->updateLadder($this->ladder, array_map('intval', $this->ladderValues), $this->admin(), $this->reason);
        } catch (DomainException $e) {
            foreach ($e->fields ?? ['ladder' => [__('errors.'.$e->errorCode->value)]] as $field => $messages) {
                $this->addError(str_replace('ladder.', 'ladderValues.', (string) $field), $messages[0]);
            }

            return;
        }

        session()->flash('status', __('admin.settings.ladder_saved', ['name' => __('admin.settings.ladders.names.'.$this->ladder)]));

        $this->cancelLadder();
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
            ->whereIn('setting_key', [...array_keys(SettingsCatalogue::ENTRIES), ...array_keys(SettingsCatalogue::TEXT_ENTRIES)])
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

        foreach (SettingsCatalogue::TEXT_ENTRIES as $key => $entry) {
            $override = $overrides->get($key);

            $groups[$entry['group']][] = [
                'key' => $key,
                'entry' => $entry,
                'text' => true,
                'default' => null,
                'current' => SettingsCatalogue::text($key),
                'override' => $override,
            ];
        }

        // Counts for the section list, before any filter: what is in each section and how much of it
        // has been moved off the default.
        $counts = [];

        foreach ($groups as $name => $rows) {
            $counts[$name] = [
                'total' => count($rows),
                'changed' => count(array_filter($rows, fn (array $row) => $row['override'] !== null)),
            ];
        }

        $ordered = array_values(array_filter(SettingsCatalogue::GROUPS, fn (string $name) => isset($groups[$name])));
        $active = in_array($this->group, $ordered, true) ? $this->group : ($ordered[0] ?? '');
        $query = trim($this->search);

        // Searching looks through every section; otherwise only the open one is drawn.
        $visible = [];

        foreach ($ordered as $name) {
            if ($query === '' && $name !== $active) {
                continue;
            }

            $rows = array_values(array_filter($groups[$name], function (array $row) use ($query): bool {
                if ($this->changedOnly && $row['override'] === null) {
                    return false;
                }

                return $query === ''
                    || mb_stripos($row['key'], $query) !== false
                    || mb_stripos((string) __(SettingsCatalogue::labelKey($row['key'])), $query) !== false;
            }));

            if ($rows !== [] || ($query === '' && $name === $active)) {
                $visible[$name] = $rows;
            }
        }

        return view('livewire.admin.settings', [
            'groups' => $visible,
            'sections' => $ordered,
            'counts' => $counts,
            'active' => $active,
            'query' => $query,
            // F02: each fee ladder with its rungs as they stand, for the ladder card.
            'ladders' => collect(SettingsLadders::LADDERS)->map(fn (array $rungs) => array_map(
                fn (string $key) => (int) PlatformSetting::value($key, SettingsCatalogue::defaultFor($key)),
                $rungs,
            ))->all(),
            // F04: every change to the open setting, newest first, from the audit log.
            'history' => $this->historyFor === null ? collect() : AdminAction::query()
                ->whereIn('action', ['settings.update', 'settings.reset'])
                ->where('entity_type', 'PlatformSetting')
                ->where('entity_id', $this->historyFor)
                ->with('admin')
                ->latest('created_at')
                ->latest('id')
                ->limit(20)
                ->get(),
        ])->title(__('admin.settings.title'));
    }
}
