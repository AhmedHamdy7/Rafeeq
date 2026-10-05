{{--
    DASHBOARD. Tiles only for the queues this admin may open; each is a link to that queue.
--}}
<div wire:poll.30s.visible>
    <h1 class="rq-h1">{{ __('admin.dashboard.title') }}</h1>
    <p class="rq-sub">{{ __('admin.trips.refreshed', ['time' => now()->timezone('Africa/Cairo')->format('H:i:s')]) }}</p>

    <div class="rq-tiles">
        @if ($safety !== null)
            <a class="rq-tile {{ $safety['alerts'] + $safety['criticalUnassigned'] > 0 ? 'rq-tile--alert' : '' }}" href="{{ route('admin.safety') }}" wire:navigate>
                <span class="rq-tile__label">{{ __('admin.dashboard.safety') }}</span>
                <span class="rq-tile__value">{{ $safety['alerts'] + $safety['reports'] }}</span>
                <span class="rq-tile__note">
                    {{ trans_choice('admin.safety.alerts_live', $safety['alerts'], ['count' => $safety['alerts']]) }}
                    @if ($safety['criticalUnassigned'] > 0)
                        &middot; {{ trans_choice('admin.dashboard.critical_unassigned', $safety['criticalUnassigned'], ['count' => $safety['criticalUnassigned']]) }}
                    @endif
                </span>
            </a>
        @endif

        @if ($trips !== null)
            <a class="rq-tile" href="{{ route('admin.trips') }}" wire:navigate>
                <span class="rq-tile__label">{{ __('admin.dashboard.trips') }}</span>
                <span class="rq-tile__value">{{ $trips['total'] }}</span>
                <span class="rq-tile__note">
                    @forelse ($trips['byStatus'] as $status => $count)
                        {{ $count }} {{ __('admin.trips.status.'.$status) }}@if (! $loop->last) &middot; @endif
                    @empty
                        {{ __('admin.trips.empty') }}
                    @endforelse
                </span>
            </a>
            <a class="rq-tile" href="{{ route('admin.trips') }}" wire:navigate>
                <span class="rq-tile__label">{{ __('admin.dashboard.seats_today') }}</span>
                <span class="rq-tile__value">{{ number_format($seatsToday) }}</span>
                <span class="rq-tile__note">{{ __('admin.dashboard.seats_today_note') }}</span>
            </a>
        @endif

        @if ($verifications !== null)
            <a class="rq-tile" href="{{ route('admin.verifications') }}" wire:navigate>
                <span class="rq-tile__label">{{ __('admin.dashboard.verifications') }}</span>
                <span class="rq-tile__value">{{ $verifications['count'] }}</span>
                <span class="rq-tile__note">
                    @if ($verifications['count'] === 0)
                        {{ __('admin.queue.empty') }}
                    @elseif ($verifications['oldestSince'] === null)
                        {{-- Waiting, with no submission time recorded (rows from before submitted_at existed). --}}
                        {{ trans_choice('admin.queue.waiting', $verifications['count'], ['count' => $verifications['count']]) }}
                    @else
                        {{ __('admin.dashboard.oldest', ['ago' => $verifications['oldestSince']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE)]) }}
                    @endif
                </span>
            </a>
        @endif

        @if ($driverApplications !== null)
            <a class="rq-tile" href="{{ route('admin.drivers') }}" wire:navigate>
                <span class="rq-tile__label">{{ __('admin.dashboard.drivers') }}</span>
                <span class="rq-tile__value">{{ $driverApplications }}</span>
                <span class="rq-tile__note">{{ trans_choice('admin.drivers.waiting', $driverApplications, ['count' => $driverApplications]) }}</span>
            </a>
        @endif

        @if ($overdueHolds !== null)
            <a class="rq-tile {{ $overdueHolds > 0 ? 'rq-tile--alert' : '' }}" href="{{ route('admin.members', ['tab' => 'suspended']) }}" wire:navigate>
                <span class="rq-tile__label">{{ __('admin.dashboard.holds') }}</span>
                <span class="rq-tile__value">{{ $overdueHolds }}</span>
                <span class="rq-tile__note">{{ __('admin.dashboard.holds_note') }}</span>
            </a>
        @endif
    </div>

    @if ($recent !== null)
        <h2 class="rq-h2">{{ __('admin.dashboard.recent') }}</h2>
        <article class="rq-card">
            <ul class="rq-checks" style="margin:0">
                @forelse ($recent as $entry)
                    @php($label = 'admin.audit.actions.'.str_replace('.', '__', $entry->action))
                    <li class="rq-check">
                        <span class="rq-check__label">{{ \Illuminate\Support\Facades\Lang::has($label) ? __($label) : $entry->action }}</span>
                        <span class="rq-note">{{ $entry->admin?->name ?? '—' }} &middot; {{ $entry->created_at?->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="rq-check"><span class="rq-check__label">{{ __('admin.audit.empty') }}</span></li>
                @endforelse
            </ul>
            <a class="rq-btn rq-btn--quiet" href="{{ route('admin.audit') }}" wire:navigate style="margin-top:10px;display:inline-flex">{{ __('admin.dashboard.full_log') }}</a>
        </article>
    @endif
</div>
