{{--
    NIGHT ESCORT. One row per corridor: watched or not, until when, by whose hand — and the
    switch to change it, with a reason, for those who may.
--}}
<div>
    <x-admin.page-head
        :title="__('admin.escort.title')"
        :sub="trans_choice('admin.escort.armed_count', $armedCount, ['count' => $armedCount]).' · '.trans_choice('admin.escort.trips_covered', $tripsCovered, ['count' => $tripsCovered])"
    />

    <div class="ad-notice" style="margin-block-end:16px">
        <x-admin.icon name="moon" />
        <span>
            @if ($autoEnabled)
                {{ __('admin.escort.auto_on', [
                    'from' => $tonightStarts->timezone('Africa/Cairo')->format('H:i'),
                    'to' => $tonightEnds->timezone('Africa/Cairo')->format('H:i'),
                ]) }}
            @else
                {{ __('admin.escort.auto_off') }}
            @endif
        </span>
    </div>

    @php($mayAct = auth('admin')->user()?->can('safety.resolve'))

    <x-admin.card flush>
        @if ($corridors->isEmpty())
            <x-admin.state kind="empty" :title="__('admin.escort.no_corridors')" />
        @else
            <ul class="ad-settings">
                @foreach ($corridors as $corridor)
                    @php($window = $windows->get($corridor->id))
                    <li wire:key="corridor-{{ $corridor->id }}" @if ($openCorridor === $corridor->id) aria-current="true" @endif>
                        <div class="ad-settings__row">
                            <div class="ad-settings__name">
                                <span style="font-weight:650"><bdi>{{ app()->getLocale() === 'ar' ? ($corridor->name_ar ?? $corridor->name) : $corridor->name }}</bdi></span>
                                <span class="ad-hint">
                                    @if ($window !== null)
                                        {{ __('admin.escort.until', ['time' => $window->ends_at->timezone('Africa/Cairo')->format('H:i')]) }}
                                        ·
                                        @if ($window->is_auto)
                                            {{ __('admin.escort.by_schedule') }}
                                        @else
                                            {{ __('admin.escort.by_admin', ['name' => $window->armedBy?->name ?? '—']) }}
                                        @endif
                                        · {{ trans_choice('admin.escort.trips_covered', $window->trips_covered, ['count' => $window->trips_covered]) }}
                                    @else
                                        {{ __('admin.escort.not_watched') }}
                                    @endif
                                </span>
                            </div>
                            <div>
                                @if ($window !== null)
                                    <span class="ad-chip ad-chip--warn">{{ __('admin.escort.on') }}</span>
                                @else
                                    <span class="ad-chip">{{ __('admin.escort.off') }}</span>
                                @endif
                            </div>
                            <div class="ad-settings__actions">
                                @if ($mayAct && $openCorridor !== $corridor->id)
                                    @if ($window !== null)
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--dangerline" wire:click="open('{{ $corridor->id }}', 'disarm')">{{ __('admin.escort.disarm') }}</button>
                                    @else
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--primary" wire:click="open('{{ $corridor->id }}', 'arm')">{{ __('admin.escort.arm') }}</button>
                                    @endif
                                @endif
                            </div>
                        </div>

                        @if ($mayAct && $openCorridor === $corridor->id)
                            <form wire:submit="{{ $mode === 'disarm' ? 'disarm' : 'arm' }}('{{ $corridor->id }}')" class="ad-confirm">
                                @if ($mode === 'arm')
                                    <div class="ad-field">
                                        <label class="ad-label" for="hours-{{ $corridor->id }}">{{ __('admin.escort.hours') }}</label>
                                        <input id="hours-{{ $corridor->id }}" type="number" class="ad-input" style="max-inline-size:160px" wire:model="hours" min="1" max="{{ $maxHours }}" required>
                                        @error('hours') <span class="ad-error">{{ $message }}</span> @enderror
                                    </div>
                                @endif
                                <div class="ad-field">
                                    <textarea class="ad-textarea" wire:model="reason" rows="2" required
                                              aria-label="{{ __('admin.escort.reason_placeholder') }}"
                                              placeholder="{{ __('admin.escort.reason_placeholder') }}"></textarea>
                                    @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="submit" class="ad-btn {{ $mode === 'disarm' ? 'ad-btn--danger' : 'ad-btn--primary' }}" wire:loading.attr="disabled">
                                        {{ $mode === 'disarm' ? __('admin.escort.disarm') : __('admin.escort.arm') }}
                                    </button>
                                    <button type="button" class="ad-btn" wire:click="$set('openCorridor', null)">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </x-admin.card>

    <x-rq-pager :page="$corridors" />
</div>
