{{--
    SETTINGS (F). One card per group; one row per number, with its default and bounds visible
    before anybody types, so a refusal is never a surprise. Every change asks for a reason and
    lands in the audit log.
--}}
@php
    use App\Domains\Admin\Support\SettingsCatalogue;
@endphp
<div>
    <x-admin.page-head :title="__('admin.settings.title')" :sub="__('admin.settings.sub')" />

    @foreach (SettingsCatalogue::GROUPS as $group)
        @continue(! isset($groups[$group]))
        <div class="ad-section-label">{{ __('admin.settings.groups.'.$group) }}</div>
        <x-admin.card flush>
            <ul class="ad-settings">
                @foreach ($groups[$group] as $row)
                    <li wire:key="setting-{{ $row['key'] }}" @if ($editing === $row['key']) aria-current="true" @endif>
                        <div class="ad-settings__row">
                            <div class="ad-settings__name">
                                <span style="font-weight:650">{{ __(SettingsCatalogue::labelKey($row['key'])) }}</span>
                                <span class="ad-code">{{ $row['key'] }}</span>
                            </div>
                            <div class="ad-settings__value">
                                @if (! empty($row['text']))
                                    <strong style="overflow-wrap:anywhere" dir="ltr">{{ $row['current'] ?? __('admin.settings.not_set') }}</strong>
                                @else
                                    <strong class="ad-num">{{ number_format((int) $row['current']) }}</strong>
                                    {{ __('admin.settings.units.'.$row['entry']['unit']) }}
                                @endif
                                <span class="ad-hint" style="display:block">
                                    @if (! empty($row['text']))
                                        @if ($row['override'])
                                            {{ __('admin.settings.text_changed', [
                                                'name' => $row['override']->updatedBy?->name ?? '—',
                                                'when' => $row['override']->updated_at?->diffForHumans() ?? '—',
                                            ]) }}
                                        @endif
                                    @elseif ($row['override'])
                                        {{ __('admin.settings.changed', [
                                            'default' => number_format((int) $row['default']),
                                            'name' => $row['override']->updatedBy?->name ?? '—',
                                            'when' => $row['override']->updated_at?->diffForHumans() ?? '—',
                                        ]) }}
                                    @else
                                        {{ __('admin.settings.is_default') }}
                                    @endif
                                </span>
                            </div>
                            <div class="ad-settings__actions">
                                @if (isset($row['entry']['locked']))
                                    <span class="ad-chip ad-chip--warn">{{ __('admin.settings.locked.'.$row['entry']['locked']) }}</span>
                                @elseif ($editing !== $row['key'])
                                    <button type="button" class="ad-btn ad-btn--sm" wire:click="edit('{{ $row['key'] }}')">{{ __('admin.settings.change') }}</button>
                                    @if ($row['override'])
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="edit('{{ $row['key'] }}', 'reset')">{{ __('admin.settings.reset') }}</button>
                                    @endif
                                    <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="$set('historyFor', {{ $historyFor === $row['key'] ? 'null' : "'".$row['key']."'" }})" aria-expanded="{{ $historyFor === $row['key'] ? 'true' : 'false' }}">{{ __('admin.settings.history') }}</button>
                                @endif
                            </div>
                        </div>

                        @if ($historyFor === $row['key'])
                            <div class="ad-confirm">
                                @if ($history->isEmpty())
                                    <p class="ad-hint" style="margin:0">{{ __('admin.settings.no_history') }}</p>
                                @else
                                    <table class="ad-table ad-table--dense"><tbody>
                                    @foreach ($history as $change)
                                        <tr wire:key="sh-{{ $change->id }}">
                                            <td class="ad-num" style="white-space:nowrap"><bdi>{{ is_scalar($change->old_value['value'] ?? null) ? $change->old_value['value'] : '—' }}</bdi> → <bdi>{{ $change->action === 'settings.reset' ? __('admin.settings.default_label') : (is_scalar($change->new_value['value'] ?? null) ? $change->new_value['value'] : '—') }}</bdi></td>
                                            <td dir="auto">{{ $change->reason }}</td>
                                            <td>{{ $change->admin?->name ?? '—' }}</td>
                                            <td class="ad-muted ad-num" style="white-space:nowrap">{{ $change->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody></table>
                                @endif
                            </div>
                        @endif

                        @if ($editing === $row['key'])
                            <form wire:submit="save" class="ad-confirm">
                                <div class="ad-field">
                                    @if ($mode === 'update' && ! empty($row['text']))
                                        <label class="ad-label" for="value-{{ $row['key'] }}">{{ __(SettingsCatalogue::labelKey($row['key'])) }}</label>
                                        <input id="value-{{ $row['key'] }}" type="{{ ($row['entry']['format'] ?? null) === 'url' ? 'url' : 'text' }}" class="ad-input" wire:model="value"
                                               maxlength="{{ $row['entry']['max'] }}" required dir="ltr">
                                    @elseif ($mode === 'update')
                                        <label class="ad-label" for="value-{{ $row['key'] }}">
                                            {{ __('admin.settings.bounds', ['min' => number_format($row['entry']['min']), 'max' => number_format($row['entry']['max']), 'unit' => __('admin.settings.units.'.$row['entry']['unit'])]) }}
                                        </label>
                                        <input id="value-{{ $row['key'] }}" type="number" class="ad-input" style="max-inline-size:200px" wire:model="value"
                                               min="{{ $row['entry']['min'] }}" max="{{ $row['entry']['max'] }}" required>
                                    @else
                                        <span class="ad-hint">{{ ! empty($row['text']) ? __('admin.settings.text_reset_hint') : __('admin.settings.reset_hint', ['default' => number_format((int) $row['default'])]) }}</span>
                                    @endif
                                    @error('value') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>

                                <div class="ad-field">
                                    <textarea class="ad-textarea" wire:model="reason" rows="2" required
                                              aria-label="{{ __('admin.settings.reason_placeholder') }}"
                                              placeholder="{{ __('admin.settings.reason_placeholder') }}"></textarea>
                                    @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>

                                <div class="ad-row">
                                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">
                                        {{ $mode === 'reset' ? __('admin.settings.confirm_reset') : __('admin.settings.save') }}
                                    </button>
                                    <button type="button" class="ad-btn" wire:click="cancel">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-admin.card>
    @endforeach
</div>
