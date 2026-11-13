{{--
    HOME (H01–H05, PH1). Live first, then the queues, then what the team did.

    Every card exists only for staff who may open the page it links to (Dashboard.php passes
    null otherwise), and every number counts the same way as that page. A queue's chip turns
    red when its oldest item has waited longer than the promise (24 hours).
--}}
@php
    $now = now('Africa/Cairo');
    $greeting = $now->hour < 12 ? 'morning' : ($now->hour < 18 ? 'afternoon' : 'evening');
    $slaHours = 24;
    $age = fn (?\DateTimeInterface $since) => $since === null ? null : \Carbon\CarbonImmutable::instance($since)->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 2);
    $late = fn (?\DateTimeInterface $since) => $since !== null && \Carbon\CarbonImmutable::instance($since)->lt(now()->subHours($slaHours));
    $sosOpen = $safety !== null && $safety['alerts'] > 0;
    $shows = fn (string $card) => in_array($card, $cards, true);
    $egp = fn (int $piastres) => __('admin/home.egp', ['amount' => number_format($piastres / 100, 2)]);
@endphp
<div wire:poll.30s.visible>
    <x-admin.page-head
        :title="__('admin/home.greeting.'.$greeting, ['name' => (string) \Illuminate\Support\Str::of((string) $admin?->name)->before(' ')])"
        :sub="__('admin/home.sub', ['date' => $now->locale(app()->getLocale())->translatedFormat('D j M'), 'time' => $now->format('H:i')])"
    >
        {{-- Customise (H01): which cards this staff member sees, saved for them. --}}
        <div class="ad-menu-anchor" x-data="{ open: false, cards: @js($cards) }" x-on:click.outside="open = false">
            <button type="button" class="ad-btn" x-on:click="open = ! open" x-bind:aria-expanded="open"><x-admin.icon name="settings" />{{ __('admin/home.customise') }}</button>
            <div class="ad-pop" x-show="open" x-cloak style="inline-size:260px">
                <div class="ad-pop__head">{{ __('admin/home.cards_title') }}</div>
                @foreach (\App\Livewire\Admin\Dashboard::CARDS as $card)
                    <label class="ad-pop__item" style="cursor:pointer">
                        <input type="checkbox" value="{{ $card }}" x-model="cards">
                        <span>{{ __('admin/home.cards.'.$card) }}</span>
                    </label>
                @endforeach
                <p class="ad-hint" style="margin:6px 10px">{{ __('admin/home.cards_hint') }}</p>
                <div style="padding:6px 10px 10px">
                    <button type="button" class="ad-btn ad-btn--primary ad-btn--sm" x-on:click="$wire.saveCards(cards); open = false">{{ __('admin/home.cards_save') }}</button>
                </div>
            </div>
        </div>
    </x-admin.page-head>

    {{-- LIVE --}}
    @if ($shows('live') && ($safety !== null || $trips !== null))
        <div class="ad-section-label">{{ __('admin/home.live') }}</div>
        <div class="ad-home-live">
            @if ($safety !== null)
                <x-admin.card flush :tone="$sosOpen ? 'alert' : null">
                    <div class="ad-stat">
                        <span class="ad-stat__label">
                            <x-admin.icon name="alert" size="14" />
                            <span style="flex:1">{{ __('admin.dashboard.safety') }}</span>
                        </span>
                        <span class="ad-stat__row">
                            <span class="ad-stat__value ad-num">{{ $safety['alerts'] + $safety['reports'] }}</span>
                            @if ($safety['criticalUnassigned'] > 0)
                                <span class="ad-chip ad-chip--bad">{{ trans_choice('admin.dashboard.critical_unassigned', $safety['criticalUnassigned'], ['count' => $safety['criticalUnassigned']]) }}</span>
                            @endif
                        </span>
                        <span class="ad-stat__foot">
                            {{ trans_choice('admin.safety.alerts_live', $safety['alerts'], ['count' => $safety['alerts']]) }}
                            · {{ trans_choice('admin/home.reports_open', $safety['reports'], ['count' => $safety['reports']]) }}
                        </span>
                        <span>
                            <a href="{{ route('admin.safety') }}" wire:navigate @class(['ad-btn ad-btn--sm', 'ad-btn--danger' => $sosOpen])>{{ __('admin/home.open_sos') }}</a>
                        </span>
                    </div>
                </x-admin.card>
            @endif

            @if ($trips !== null)
                <x-admin.card flush>
                    <div class="ad-stat">
                        <span class="ad-stat__label">
                            <x-admin.icon name="radio" size="14" />
                            <span style="flex:1">{{ __('admin/home.running_now') }}</span>
                            <a href="{{ route('admin.trips') }}" wire:navigate class="ad-link" style="font-size:12.5px">{{ __('admin/shell.nav.live_trips') }}</a>
                        </span>
                        <div class="ad-figures">
                            @foreach (['preparing', 'en_route', 'at_pickup', 'in_progress', 'emergency'] as $status)
                                @if ($status !== 'emergency' || ($trips['byStatus'][$status] ?? 0) > 0)
                                    <div @class(['ad-figure', 'ad-figure--bad' => $status === 'emergency'])>
                                        <span class="ad-figure__value ad-num">{{ $trips['byStatus'][$status] ?? 0 }}</span>
                                        <span class="ad-figure__label">{{ __('admin.trips.status.'.$status) }}</span>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </x-admin.card>

                <x-admin.card flush>
                    <div class="ad-stat">
                        <span class="ad-stat__label"><x-admin.icon name="calendar" size="14" /><span style="flex:1">{{ __('admin/home.trip_days') }}</span></span>
                        <div class="ad-figures">
                            @foreach (['scheduled', 'completed', 'cancelled'] as $key)
                                <div class="ad-figure">
                                    <span class="ad-figure__value ad-num">{{ $tripDays[$key] ?? 0 }}</span>
                                    <span class="ad-figure__label">{{ __('admin/home.days.'.$key) }}</span>
                                </div>
                            @endforeach
                        </div>
                        <span class="ad-stat__foot">{{ __('admin/home.seats_today', ['count' => number_format($seatsToday ?? 0)]) }}</span>
                    </div>
                </x-admin.card>
            @endif
        </div>
    @endif

    {{-- QUEUES --}}
    @php
        $queues = array_values(array_filter([
            $driverApplications !== null ? ['label' => __('admin/home.queues.drivers'), 'count' => $driverApplications, 'since' => null, 'route' => 'admin.drivers'] : null,
            $replacementCars !== null ? ['label' => __('admin/home.queues.replacement_cars'), 'count' => $replacementCars, 'since' => null, 'route' => 'admin.replacement-cars'] : null,
            $verifications !== null ? ['label' => __('admin/home.queues.identity'), 'count' => $verifications['count'], 'since' => $verifications['oldestSince'], 'route' => 'admin.verifications'] : null,
            $payments !== null ? ['label' => __('admin/home.queues.payments'), 'count' => $payments['count'], 'since' => $payments['oldestSince'], 'route' => 'admin.payments'] : null,
            $appeals !== null ? ['label' => __('admin/home.queues.appeals'), 'count' => $appeals['count'], 'since' => $appeals['oldestSince'], 'route' => $payments !== null ? 'admin.payments' : 'admin.members'] : null,
            $overdueHolds !== null ? ['label' => __('admin.dashboard.holds'), 'count' => $overdueHolds, 'since' => null, 'route' => 'admin.members', 'params' => ['tab' => 'suspended'], 'bad' => $overdueHolds > 0] : null,
            $escort !== null ? ['label' => __('admin.dashboard.escort'), 'count' => $escort['armed'], 'since' => null, 'route' => 'admin.escort', 'foot' => trans_choice('admin.escort.trips_covered', $escort['tripsCovered'], ['count' => $escort['tripsCovered']])] : null,
        ]));
    @endphp

    @if ($shows('queues') && $queues !== [])
        <div class="ad-section-label">{{ __('admin/home.queues_label') }}</div>
        <div class="ad-grid ad-grid--4">
            @foreach ($queues as $queue)
                @php
                    $isLate = ($queue['bad'] ?? false) || $late($queue['since']);
                    $chip = $queue['count'] === 0
                        ? __('admin/home.chip.clear')
                        : ($isLate ? __('admin/home.chip.late', ['hours' => $slaHours]) : __('admin/home.chip.on_time'));
                @endphp
                <x-admin.card flush>
                    <x-admin.stat
                        :href="route($queue['route'], $queue['params'] ?? [])"
                        :label="$queue['label']"
                        :value="$queue['count']"
                        :chip="$chip"
                        :chip-tone="$queue['count'] === 0 ? 'ok' : ($isLate ? 'bad' : 'ok')"
                        :foot="$queue['foot'] ?? ($queue['since'] !== null && $queue['count'] > 0
                            ? __('admin/home.oldest', ['age' => '<strong>'.e($age($queue['since'])).'</strong>'])
                            : null)"
                    />
                </x-admin.card>
            @endforeach
        </div>
    @endif

    {{-- WATCH LIST (H03): what the rules will act on soon unless somebody does first. --}}
    @php
        $watch = array_values(array_filter([
            $watchDebts !== null ? ['label' => __('admin/home.watch.drivers_owing'), 'count' => $watchDebts['driversOwing'], 'unit' => 'drivers',
                'detail' => trans_choice('admin/home.watch.drivers_owing_detail', $watchDebts['driversBlocked'], ['count' => $watchDebts['driversBlocked']]), 'rule' => 'PAY-12 · PAY-45', 'route' => 'admin.settlements'] : null,
            $watchDebts !== null ? ['label' => __('admin/home.watch.passengers_near'), 'count' => $watchDebts['passengersNear'], 'unit' => 'passengers',
                'detail' => __('admin/home.watch.passengers_near_detail'), 'rule' => 'PAY-17', 'route' => 'admin.ledger'] : null,
            $watchPapers !== null ? ['label' => __('admin/home.watch.papers', ['days' => \App\Domains\Admin\Support\HomeWatch::EXPIRY_DAYS]), 'count' => $watchPapers['licences'] + $watchPapers['carPapers'], 'unit' => 'papers',
                'detail' => __('admin/home.watch.papers_detail', ['licences' => $watchPapers['licences'], 'cars' => $watchPapers['carPapers']]), 'rule' => 'PROF-33', 'route' => 'admin.vehicles'] : null,
            $watchGender !== null ? ['label' => __('admin/home.watch.gender_review'), 'count' => $watchGender, 'unit' => 'commutes',
                'detail' => __('admin/home.watch.gender_review_detail'), 'rule' => 'COM-45', 'route' => 'admin.commutes'] : null,
        ]));
    @endphp
    @if ($shows('watch') && $watch !== [])
        <div class="ad-section-label">{{ __('admin/home.cards.watch') }}</div>
        <x-admin.card flush>
            <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                <thead><tr><th>{{ __('admin/home.cards.watch') }}</th><th class="ad-end">{{ __('admin/home.watch.count') }}</th><th>{{ __('admin/home.watch.detail') }}</th><th>{{ __('admin/home.watch.rule') }}</th></tr></thead>
                <tbody>
                @foreach ($watch as $line)
                    <tr>
                        <td data-label="{{ __('admin/home.cards.watch') }}"><a class="ad-link" href="{{ route($line['route']) }}" wire:navigate>{{ $line['label'] }}</a></td>
                        <td data-label="{{ __('admin/home.watch.count') }}" class="ad-end"><span @class(['ad-chip', 'ad-chip--warn' => $line['count'] > 0, 'ad-chip--ok' => $line['count'] === 0])>{{ trans_choice('admin/home.watch.units.'.$line['unit'], $line['count'], ['count' => $line['count']]) }}</span></td>
                        <td data-label="{{ __('admin/home.watch.detail') }}" class="ad-muted">{{ $line['detail'] }}</td>
                        <td data-label="{{ __('admin/home.watch.rule') }}"><span class="ad-code">{{ $line['rule'] }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </x-admin.card>
    @endif

    {{-- MONEY SNAPSHOT (H04): this settlement week. --}}
    @if ($shows('money') && $money !== null)
        <div class="ad-section-label">{{ __('admin/home.money_title', ['from' => $money['weekStart']->locale(app()->getLocale())->translatedFormat('D j'), 'to' => $money['weekStart']->addDays(6)->locale(app()->getLocale())->translatedFormat('D j M')]) }}</div>
        <div class="ad-grid ad-grid--4">
            <x-admin.card flush><x-admin.stat money :label="__('admin/home.money.commission')" :value="$egp($money['commission'])" :href="route('admin.settlements')" /></x-admin.card>
            <x-admin.card flush><x-admin.stat money :label="__('admin/home.money.received')" :value="$egp($money['received'])" :href="route('admin.payments')" /></x-admin.card>
            <x-admin.card flush><x-admin.stat money :label="__('admin/home.money.owed_now')" :value="$egp($money['owedNow'])" :href="route('admin.ledger')" /></x-admin.card>
            <x-admin.card flush><x-admin.stat money :label="__('admin/home.money.suspended_owe')" :value="$egp($money['suspendedOwe'])" :href="route('admin.settlements')" /></x-admin.card>
        </div>
    @endif

    @if ($safety === null && $trips === null && $queues === [] && $recent === null)
        <x-admin.card flush style="margin-block-start:20px"><x-admin.state kind="empty" :title="__('admin/home.nothing_title')" :text="__('admin/home.nothing_text')" /></x-admin.card>
    @endif

    {{-- WHAT THE TEAM DID --}}
    @if ($shows('team') && $recent !== null)
        <div class="ad-section-label">{{ __('admin/home.team') }}</div>
        <x-admin.card flush>
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin.dashboard.recent') }}</th>
                        <th>{{ __('admin/home.who') }}</th>
                        <th class="ad-end">{{ __('admin/home.when') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($recent as $entry)
                        @php($label = 'admin.audit.actions.'.str_replace('.', '__', $entry->action))
                        <tr>
                            <td data-label="{{ __('admin/home.what') }}">{{ \Illuminate\Support\Facades\Lang::has($label) ? __($label) : $entry->action }}</td>
                            <td data-label="{{ __('admin/home.who') }}">{{ $entry->admin?->name ?? '—' }}</td>
                            <td data-label="{{ __('admin/home.when') }}" class="ad-end ad-num ad-muted">
                                {{ $entry->created_at?->timezone('Africa/Cairo')->isToday() ? $entry->created_at->timezone('Africa/Cairo')->format('H:i') : $entry->created_at?->timezone('Africa/Cairo')->format('j M') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="3"><x-admin.state kind="empty" :title="__('admin.audit.empty')" /></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div style="padding:10px 12px;border-block-start:1px solid var(--border-default)">
                <a class="ad-link" href="{{ route('admin.audit') }}" wire:navigate>{{ __('admin.dashboard.full_log') }} <x-admin.icon name="chevron" size="12" class="ad-flip" /></a>
            </div>
        </x-admin.card>
    @endif
</div>
