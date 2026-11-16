{{--
    SETTINGS (F). One card per group; one row per number, with its default and bounds visible
    before anybody types, so a refusal is never a surprise. Every change asks for a reason and
    lands in the audit log.
--}}
@php
    use App\Domains\Admin\Support\SettingsCatalogue;
    use App\Domains\Admin\Support\SettingsLadders;

    $egp = fn (int $piastres) => __('admin/home.egp', ['amount' => number_format($piastres / 100, 2)]);
@endphp
<div>
    <x-admin.page-head :title="__('admin.settings.title')" :sub="__('admin.settings.sub')" />

    @foreach (SettingsCatalogue::GROUPS as $group)
        @continue(! isset($groups[$group]))
        <div class="ad-section-label">{{ __('admin.settings.groups.'.$group) }}</div>
        @if ($group === 'fees')
            {{-- F02 · The fee ladders, edited whole, with every rung priced on an EGP 110 seat. --}}
            <x-admin.card :title="__('admin.settings.ladders.title')" bar style="margin-block-end:12px">
                <p class="ad-hint" style="margin:0 0 12px">{{ __('admin.settings.ladders.hint', ['seat' => $egp(SettingsLadders::EXAMPLE_SEAT_PIASTRES)]) }}</p>
                @foreach ($ladders as $name => $rungs)
                    <div class="ad-ladder" wire:key="ladder-{{ $name }}">
                        <div class="ad-row" style="justify-content:space-between;gap:8px">
                            <strong>{{ __('admin.settings.ladders.names.'.$name) }}</strong>
                            @if ($ladder !== $name)
                                <button type="button" class="ad-btn ad-btn--sm" wire:click="editLadder('{{ $name }}')">{{ __('admin.settings.ladders.edit') }}</button>
                            @endif
                        </div>

                        @if ($ladder === $name)
                            <form wire:submit="saveLadder" class="ad-confirm">
                                @error('ladder') <p class="ad-flash ad-flash--error"><x-admin.icon name="alert" />{{ $message }}</p> @enderror
                                <div class="ad-table-wrap"><table class="ad-table ad-table--dense">
                                    <thead><tr>
                                        <th>{{ __('admin.settings.ladders.rung_col') }}</th>
                                        <th class="ad-end">{{ __('admin.settings.ladders.now') }}</th>
                                        <th>{{ __('admin.settings.ladders.new') }}</th>
                                        <th class="ad-end">{{ __('admin.settings.ladders.on_seat') }}</th>
                                    </tr></thead>
                                    <tbody>
                                    @foreach ($rungs as $rung => $current)
                                        @php($next = is_numeric($ladderValues[$rung] ?? null) ? (int) $ladderValues[$rung] : $current)
                                        <tr>
                                            <td>{{ __('admin.settings.ladders.rungs.'.$name.'.'.$rung) }}</td>
                                            <td class="ad-end ad-num">{{ $current }}%</td>
                                            <td>
                                                <input type="number" class="ad-input" style="max-inline-size:110px" min="0" max="300" step="1" wire:model.live.debounce.300ms="ladderValues.{{ $rung }}" aria-label="{{ __('admin.settings.ladders.rung', ['rung' => $rung]) }}" dir="ltr">
                                                @error('ladderValues.'.$rung) <span class="ad-error">{{ $message }}</span> @enderror
                                            </td>
                                            <td class="ad-end ad-num">
                                                @if ($next !== $current)
                                                    <span class="ad-muted"><s>{{ $egp(SettingsLadders::example($current)) }}</s></span> → <strong>{{ $egp(SettingsLadders::example($next)) }}</strong>
                                                @else
                                                    {{ $egp(SettingsLadders::example($current)) }}
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table></div>
                                <div class="ad-field" style="margin-block-start:10px">
                                    <label class="ad-label" for="ladder-reason">{{ __('admin.settings.reason') }}</label>
                                    <textarea id="ladder-reason" class="ad-textarea" rows="2" wire:model="reason" required dir="auto"></textarea>
                                    @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin.settings.ladders.save') }}</button>
                                    <button type="button" class="ad-btn" wire:click="cancelLadder">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </form>
                        @else
                            <p class="ad-muted ad-num" style="margin:4px 0 0;font-size:12.5px">
                                @foreach ($rungs as $rung => $current)
                                    {{ __('admin.settings.ladders.rungs.'.$name.'.'.$rung) }}: <strong>{{ $current }}%</strong> ({{ $egp(SettingsLadders::example($current)) }})@if (! $loop->last) · @endif
                                @endforeach
                            </p>
                        @endif
                    </div>
                @endforeach
            </x-admin.card>
        @endif
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
                                    <div class="ad-table-wrap"><table class="ad-table ad-table--dense"><tbody>
                                    @foreach ($history as $change)
                                        <tr wire:key="sh-{{ $change->id }}">
                                            <td class="ad-num" style="white-space:nowrap"><bdi>{{ is_scalar($change->old_value['value'] ?? null) ? $change->old_value['value'] : '—' }}</bdi> → <bdi>{{ $change->action === 'settings.reset' ? __('admin.settings.default_label') : (is_scalar($change->new_value['value'] ?? null) ? $change->new_value['value'] : '—') }}</bdi></td>
                                            <td dir="auto">{{ $change->reason }}</td>
                                            <td>{{ $change->admin?->name ?? '—' }}</td>
                                            <td class="ad-muted ad-num" style="white-space:nowrap">{{ $change->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody></table></div>
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
