{{--
    SETTINGS. One card per group; one row per number, with its default and bounds visible
    before anybody types, so a refusal is never a surprise.
--}}
@php
    use App\Domains\Admin\Support\SettingsCatalogue;
@endphp
<div>
    <h1 class="rq-h1">{{ __('admin.settings.title') }}</h1>
    <p class="rq-sub">{{ __('admin.settings.sub') }}</p>

    @foreach (SettingsCatalogue::GROUPS as $group)
        @continue(! isset($groups[$group]))
        <h2 class="rq-h2">{{ __('admin.settings.groups.'.$group) }}</h2>
        <article class="rq-card">
            <ul class="rq-checks" style="margin:0">
                @foreach ($groups[$group] as $row)
                    <li class="rq-check" wire:key="setting-{{ $row['key'] }}" style="flex-wrap:wrap;align-items:flex-start">
                        <span class="rq-check__label" style="flex:1;min-width:220px">
                            <span style="font-weight:600;display:block">{{ __(SettingsCatalogue::labelKey($row['key'])) }}</span>
                            <span class="rq-note rq-mono">{{ $row['key'] }}</span>
                        </span>
                        <span style="text-align:end">
                            <span style="font-weight:700">{{ number_format((int) $row['current']) }}</span>
                            {{ __('admin.settings.units.'.$row['entry']['unit']) }}
                            <span class="rq-note" style="display:block">
                                @if ($row['override'])
                                    {{ __('admin.settings.changed', [
                                        'default' => number_format((int) $row['default']),
                                        'name' => $row['override']->updatedBy?->name ?? '—',
                                        'when' => $row['override']->updated_at?->diffForHumans() ?? '—',
                                    ]) }}
                                @else
                                    {{ __('admin.settings.is_default') }}
                                @endif
                            </span>
                        </span>
                        <span style="display:flex;gap:6px;flex-basis:100%;justify-content:flex-end">
                            @if (isset($row['entry']['locked']))
                                <span class="rq-pill rq-pill--warn">{{ __('admin.settings.locked.'.$row['entry']['locked']) }}</span>
                            @elseif ($editing !== $row['key'])
                                <button type="button" class="rq-btn rq-btn--quiet" wire:click="edit('{{ $row['key'] }}')">{{ __('admin.settings.change') }}</button>
                                @if ($row['override'])
                                    <button type="button" class="rq-btn rq-btn--quiet" wire:click="edit('{{ $row['key'] }}', 'reset')">{{ __('admin.settings.reset') }}</button>
                                @endif
                            @endif
                        </span>

                        @if ($editing === $row['key'])
                            <form wire:submit="save" style="flex-basis:100%;margin-top:8px">
                                @if ($mode === 'update')
                                    <label class="rq-label" for="value-{{ $row['key'] }}">
                                        {{ __('admin.settings.bounds', ['min' => number_format($row['entry']['min']), 'max' => number_format($row['entry']['max']), 'unit' => __('admin.settings.units.'.$row['entry']['unit'])]) }}
                                    </label>
                                    <input id="value-{{ $row['key'] }}" type="number" class="rq-field" wire:model="value"
                                           min="{{ $row['entry']['min'] }}" max="{{ $row['entry']['max'] }}" required>
                                @else
                                    <p class="rq-note" style="margin:0 0 6px">{{ __('admin.settings.reset_hint', ['default' => number_format((int) $row['default'])]) }}</p>
                                @endif
                                @error('value') <p class="rq-error">{{ $message }}</p> @enderror

                                <textarea class="rq-field" wire:model="reason" rows="2" required style="margin-top:8px"
                                          placeholder="{{ __('admin.settings.reason_placeholder') }}"></textarea>
                                @error('reason') <p class="rq-error">{{ $message }}</p> @enderror

                                <div class="rq-actions">
                                    <button type="submit" class="rq-btn rq-btn--primary" wire:loading.attr="disabled">
                                        {{ $mode === 'reset' ? __('admin.settings.confirm_reset') : __('admin.settings.save') }}
                                    </button>
                                    <button type="button" class="rq-btn rq-btn--quiet" wire:click="cancel">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </article>
    @endforeach
</div>
