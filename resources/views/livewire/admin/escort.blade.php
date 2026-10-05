{{--
    NIGHT ESCORT. One row per corridor: watched or not, until when, by whose hand — and the
    switch to change it, with a reason, for those who may.
--}}
<div>
    <h1 class="rq-h1">{{ __('admin.escort.title') }}</h1>
    <p class="rq-sub">
        {{ trans_choice('admin.escort.armed_count', $armedCount, ['count' => $armedCount]) }}
        &middot;
        {{ trans_choice('admin.escort.trips_covered', $tripsCovered, ['count' => $tripsCovered]) }}
    </p>

    <p class="rq-note" style="margin-bottom:16px">
        @if ($autoEnabled)
            {{ __('admin.escort.auto_on', [
                'from' => $tonightStarts->timezone('Africa/Cairo')->format('H:i'),
                'to' => $tonightEnds->timezone('Africa/Cairo')->format('H:i'),
            ]) }}
        @else
            {{ __('admin.escort.auto_off') }}
        @endif
    </p>

    @php($mayAct = auth('admin')->user()?->can('safety.resolve'))

    @forelse ($corridors as $corridor)
        @php($window = $windows->get($corridor->id))
        <article class="rq-card" wire:key="corridor-{{ $corridor->id }}">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                    <div style="font-weight:600"><bdi>{{ app()->getLocale() === 'ar' ? ($corridor->name_ar ?? $corridor->name) : $corridor->name }}</bdi></div>
                    <div class="rq-note">
                        @if ($window !== null)
                            {{ __('admin.escort.until', ['time' => $window->ends_at->timezone('Africa/Cairo')->format('H:i')]) }}
                            &middot;
                            @if ($window->is_auto)
                                {{ __('admin.escort.by_schedule') }}
                            @else
                                {{ __('admin.escort.by_admin', ['name' => $window->armedBy?->name ?? '—']) }}
                            @endif
                            &middot;
                            {{ trans_choice('admin.escort.trips_covered', $window->trips_covered, ['count' => $window->trips_covered]) }}
                        @else
                            {{ __('admin.escort.not_watched') }}
                        @endif
                    </div>
                </div>
                @if ($window !== null)
                    <span class="rq-pill rq-pill--warn">{{ __('admin.escort.on') }}</span>
                @else
                    <span class="rq-pill rq-pill--count">{{ __('admin.escort.off') }}</span>
                @endif
            </div>

            @if ($mayAct)
                @if ($openCorridor === $corridor->id)
                    <form wire:submit="{{ $mode === 'disarm' ? 'disarm' : 'arm' }}('{{ $corridor->id }}')" style="margin-top:13px">
                        @if ($mode === 'arm')
                            <label class="rq-label" for="hours-{{ $corridor->id }}">{{ __('admin.escort.hours') }}</label>
                            <input id="hours-{{ $corridor->id }}" type="number" class="rq-field" wire:model="hours" min="1" max="{{ $maxHours }}" required>
                            @error('hours') <p class="rq-error">{{ $message }}</p> @enderror
                        @endif

                        <textarea class="rq-field" wire:model="reason" rows="2" required style="margin-top:10px"
                                  placeholder="{{ __('admin.escort.reason_placeholder') }}"></textarea>
                        @error('reason') <p class="rq-error">{{ $message }}</p> @enderror

                        <div class="rq-actions">
                            <button type="submit" class="rq-btn {{ $mode === 'disarm' ? 'rq-btn--danger' : 'rq-btn--primary' }}" wire:loading.attr="disabled">
                                {{ $mode === 'disarm' ? __('admin.escort.disarm') : __('admin.escort.arm') }}
                            </button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openCorridor', null)">
                                {{ __('admin.queue.cancel') }}
                            </button>
                        </div>
                    </form>
                @else
                    <div class="rq-actions">
                        @if ($window !== null)
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="open('{{ $corridor->id }}', 'disarm')">{{ __('admin.escort.disarm') }}</button>
                        @else
                            <button type="button" class="rq-btn rq-btn--primary" wire:click="open('{{ $corridor->id }}', 'arm')">{{ __('admin.escort.arm') }}</button>
                        @endif
                    </div>
                @endif
            @endif
        </article>
    @empty
        <p class="rq-note">{{ __('admin.escort.no_corridors') }}</p>
    @endforelse

    <x-rq-pager :page="$corridors" />
</div>
