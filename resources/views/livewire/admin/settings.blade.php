{{--
    SETTINGS (F). Sections down the side, one section on screen at a time; a search box that
    looks through all of them; "changed only" to see what has been moved off the defaults.

    Each row: what it is, the value now (large), whether it differs from the default. Changing a
    value opens under the row with the allowed range as a slider and a number box, a before → after
    preview, and the reason the audit log needs. Every change still asks for that reason.
--}}
@php
    use App\Domains\Admin\Support\SettingsCatalogue;
    use App\Domains\Admin\Support\SettingsLadders;

    $egp = fn (int $piastres) => __('admin/home.egp', ['amount' => number_format($piastres / 100, 2)]);

    $icons = [
        'auth' => 'lock', 'profile' => 'users', 'commute' => 'route', 'booking' => 'ticket',
        'debt' => 'receipt', 'fees' => 'scale', 'trip' => 'car', 'rating' => 'check',
        'reliability' => 'chart', 'safety' => 'shield', 'chat' => 'inbox', 'support' => 'headset',
        'admin' => 'settings',
    ];
@endphp
<div>
    <x-admin.page-head :title="__('admin.settings.title')" :sub="__('admin.settings.sub')" />

    <div class="ad-toolbar">
        <label class="ad-search" style="flex:1;min-inline-size:220px">
            <x-admin.icon name="search" />
            <input type="search" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('admin.settings.search') }}" aria-label="{{ __('admin.settings.search') }}">
        </label>
        <button type="button" @class(['ad-btn', 'ad-btn--primary' => $changedOnly]) wire:click="$toggle('changedOnly')" aria-pressed="{{ $changedOnly ? 'true' : 'false' }}">
            {{ __('admin.settings.changed_only') }}
            <span class="ad-set-count">{{ array_sum(array_column($counts, 'changed')) }}</span>
        </button>
    </div>

    <div class="ad-set-layout">
        {{-- Sections. A column on a wide screen, a row of chips that scrolls on a phone. --}}
        <nav class="ad-set-nav" aria-label="{{ __('admin.settings.sections') }}">
            @foreach ($sections as $name)
                <button type="button" wire:key="nav-{{ $name }}" wire:click="showGroup('{{ $name }}')"
                        @class(['ad-set-nav__item', 'is-active' => $query === '' && $active === $name])
                        @if ($query === '' && $active === $name) aria-current="page" @endif>
                    <x-admin.icon :name="$icons[$name] ?? 'settings'" size="16" />
                    <span class="ad-set-nav__label">{{ __('admin.settings.groups.'.$name) }}</span>
                    @if ($counts[$name]['changed'] > 0)
                        <span class="ad-set-nav__dot" title="{{ __('admin.settings.count_changed', ['count' => $counts[$name]['changed']]) }}"></span>
                    @endif
                    <span class="ad-set-nav__count">{{ $counts[$name]['total'] }}</span>
                </button>
            @endforeach
        </nav>

        <div class="ad-set-main">
            @if ($query !== '')
                <div class="ad-set-head">
                    <h2 class="ad-set-head__title">{{ __('admin.settings.results', ['q' => $query]) }}</h2>
                </div>
                @if ($groups === [])
                    <x-admin.card><x-admin.state kind="empty" :title="__('admin.settings.no_results', ['q' => $query])" /></x-admin.card>
                @endif
            @endif

            @foreach ($groups as $group => $rows)
                @if ($query === '')
                    <div class="ad-set-head">
                        <h2 class="ad-set-head__title">
                            <x-admin.icon :name="$icons[$group] ?? 'settings'" size="18" />
                            {{ __('admin.settings.groups.'.$group) }}
                        </h2>
                        <span class="ad-muted">
                            {{ __('admin.settings.count', ['count' => $counts[$group]['total']]) }}@if ($counts[$group]['changed'] > 0) · {{ __('admin.settings.count_changed', ['count' => $counts[$group]['changed']]) }}@endif
                        </span>
                    </div>
                @else
                    <div class="ad-section-label">{{ __('admin.settings.groups.'.$group) }}</div>
                @endif

                @if ($group === 'fees' && $query === '' && ! $changedOnly)
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

                @if ($rows === [])
                    <x-admin.card><x-admin.state kind="empty" :title="__('admin.settings.no_changed')" /></x-admin.card>
                @else
                <div class="ad-set-list">
                    @foreach ($rows as $row)
                        @php($isText = ! empty($row['text']))
                        @php($isEditing = $editing === $row['key'])
                        @php($unit = $isText ? '' : __('admin.settings.units.'.$row['entry']['unit']))
                        <article wire:key="setting-{{ $row['key'] }}" @class(['ad-set-row', 'is-open' => $isEditing, 'is-changed' => $row['override'] !== null])>
                            <div class="ad-set-row__main">
                                <div class="ad-set-row__info">
                                    <div class="ad-set-row__title">
                                        {{ __(SettingsCatalogue::labelKey($row['key'])) }}
                                        @if ($row['override'] !== null)
                                            <span class="ad-chip ad-chip--brand">{{ __('admin.settings.chip_changed') }}</span>
                                        @endif
                                    </div>
                                    <div class="ad-set-row__meta">
                                        <span class="ad-code" dir="ltr">{{ $row['key'] }}</span>
                                        @if ($isText)
                                            @if ($row['override'])
                                                <span>{{ __('admin.settings.text_changed', ['name' => $row['override']->updatedBy?->name ?? '—', 'when' => $row['override']->updated_at?->diffForHumans() ?? '—']) }}</span>
                                            @endif
                                        @elseif ($row['override'])
                                            <span>{{ __('admin.settings.changed', ['default' => number_format((int) $row['default']), 'name' => $row['override']->updatedBy?->name ?? '—', 'when' => $row['override']->updated_at?->diffForHumans() ?? '—']) }}</span>
                                        @else
                                            <span>{{ __('admin.settings.is_default') }} · {{ number_format($row['entry']['min']) }}–{{ number_format($row['entry']['max']) }} {{ $unit }}</span>
                                        @endif
                                    </div>
                                    @if ($row['key'] === 'pricing.commission_rate_percent')
                                        @php($example = \App\Domains\Commute\Support\SeatPricing::exampleAtRate((int) $row['current']))
                                        <div class="ad-hint ad-num">{{ __('admin.settings.commission_example', ['rate' => (int) $row['current'], 'share' => $egp($example['share']), 'commission' => $egp($example['commission']), 'seat' => $egp($example['seat'])]) }}</div>
                                    @endif
                                </div>

                                <div class="ad-set-row__value">
                                    @if ($isText)
                                        <span class="ad-set-row__text" dir="ltr">{{ $row['current'] ?? __('admin.settings.not_set') }}</span>
                                    @else
                                        <span class="ad-set-row__num ad-num">{{ number_format((int) $row['current']) }}</span>
                                        <span class="ad-set-row__unit">{{ $unit }}</span>
                                    @endif
                                </div>

                                <div class="ad-set-row__actions">
                                    @if (isset($row['entry']['locked']))
                                        <span class="ad-chip ad-chip--warn">{{ __('admin.settings.locked.'.$row['entry']['locked']) }}</span>
                                    @elseif (! $isEditing)
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--primary" wire:click="edit('{{ $row['key'] }}')">{{ __('admin.settings.change') }}</button>
                                        @if ($row['override'] && empty($row['entry']['required']))
                                            <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="edit('{{ $row['key'] }}', 'reset')">{{ __('admin.settings.reset') }}</button>
                                        @endif
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--quiet" wire:click="$set('historyFor', {{ $historyFor === $row['key'] ? 'null' : "'".$row['key']."'" }})" aria-expanded="{{ $historyFor === $row['key'] ? 'true' : 'false' }}">{{ __('admin.settings.history') }}</button>
                                    @endif
                                </div>
                            </div>

                            @if ($historyFor === $row['key'])
                                <div class="ad-set-row__panel">
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

                            @if ($isEditing)
                                <form wire:submit="save" class="ad-set-row__panel">
                                    @if ($mode === 'update' && $isText)
                                        <div class="ad-field">
                                            <label class="ad-label" for="value-{{ $row['key'] }}">{{ __('admin.settings.new_value') }}</label>
                                            <input id="value-{{ $row['key'] }}" type="{{ ($row['entry']['format'] ?? null) === 'url' ? 'url' : (($row['entry']['format'] ?? null) === 'email' ? 'email' : 'text') }}" class="ad-input" wire:model="value"
                                                   maxlength="{{ $row['entry']['max'] }}" required dir="ltr" autofocus>
                                            @error('value') <span class="ad-error">{{ $message }}</span> @enderror
                                        </div>
                                    @elseif ($mode === 'update')
                                        @php($next = is_numeric($value) ? (int) $value : null)
                                        <div class="ad-set-edit">
                                            <div class="ad-set-edit__compare">
                                                <div>
                                                    <span class="ad-hint">{{ __('admin.settings.now') }}</span>
                                                    <span class="ad-set-row__num ad-num">{{ number_format((int) $row['current']) }}</span>
                                                </div>
                                                <x-admin.icon name="chevron" size="16" class="ad-flip ad-muted" />
                                                <div>
                                                    <span class="ad-hint">{{ __('admin.settings.new_value') }}</span>
                                                    <span @class(['ad-set-row__num', 'ad-num', 'is-different' => $next !== null && $next !== (int) $row['current']])>{{ $next === null ? '—' : number_format($next) }}</span>
                                                </div>
                                                <span class="ad-muted">{{ $unit }}</span>
                                            </div>

                                            <div class="ad-set-edit__inputs">
                                                <div class="ad-set-edit__slider" dir="ltr">
                                                    <input type="range" class="ad-set-range" min="{{ $row['entry']['min'] }}" max="{{ $row['entry']['max'] }}" step="1"
                                                           wire:model.live.debounce.150ms="value" aria-label="{{ __(SettingsCatalogue::labelKey($row['key'])) }}">
                                                    <div class="ad-set-edit__scale ad-hint ad-num">
                                                        <span>{{ number_format($row['entry']['min']) }}</span>
                                                        <span>{{ __('admin.settings.default_value', ['value' => number_format((int) $row['default'])]) }}</span>
                                                        <span>{{ number_format($row['entry']['max']) }}</span>
                                                    </div>
                                                </div>
                                                <input id="value-{{ $row['key'] }}" type="number" class="ad-input ad-num" style="max-inline-size:140px"
                                                       min="{{ $row['entry']['min'] }}" max="{{ $row['entry']['max'] }}" wire:model.live.debounce.300ms="value" required dir="ltr"
                                                       aria-label="{{ __('admin.settings.new_value') }}">
                                            </div>
                                            @if ($row['key'] === 'pricing.commission_rate_percent' && $next !== null)
                                                @php($example = \App\Domains\Commute\Support\SeatPricing::exampleAtRate(max(0, $next)))
                                                <span class="ad-hint ad-num">{{ __('admin.settings.commission_example', ['rate' => max(0, $next), 'share' => $egp($example['share']), 'commission' => $egp($example['commission']), 'seat' => $egp($example['seat'])]) }}</span>
                                            @endif
                                            @error('value') <span class="ad-error">{{ $message }}</span> @enderror
                                        </div>
                                    @else
                                        <p class="ad-hint" style="margin:0">{{ $isText ? __('admin.settings.text_reset_hint') : __('admin.settings.reset_hint', ['default' => number_format((int) $row['default'])]) }}</p>
                                        @error('value') <span class="ad-error">{{ $message }}</span> @enderror
                                    @endif

                                    <div class="ad-field">
                                        <label class="ad-label" for="reason-{{ $row['key'] }}">{{ __('admin.settings.reason_placeholder') }}</label>
                                        <textarea id="reason-{{ $row['key'] }}" class="ad-textarea" wire:model="reason" rows="2" required dir="auto"></textarea>
                                        <span class="ad-hint">{{ __('admin.settings.reason_hint') }}</span>
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
                        </article>
                    @endforeach
                </div>
                @endif
            @endforeach
        </div>
    </div>
</div>
