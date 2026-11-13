{{--
    STATISTICS (T01–T05). One period (today, a settlement week, a month), compared with the
    one before it. A period still running says "so far". Every number is defined on the
    Definitions tab with the same words used here.
--}}
@php
    $egp = fn ($piastres) => 'EGP '.number_format(((int) $piastres) / 100, 2);
    $change = function ($now, $before) {
        $pct = \App\Domains\Admin\Support\Statistics::change($now ?? 0, $before ?? 0);

        return $pct === null ? null : ['pct' => $pct, 'up' => $pct >= 0];
    };
    $range = fn ($p) => $p->from->locale(app()->getLocale())->translatedFormat('D j M').' – '.$p->until->subDay()->locale(app()->getLocale())->translatedFormat('D j M');
@endphp
<div>
    <x-admin.page-head
        :title="__('admin/stats.title')"
        :sub="__('admin/stats.periods.'.$current->key).($current->isRunning() ? ' '.__('admin/stats.so_far') : '').' · '.$range($current).' · '.__('admin/stats.compared', ['range' => $range($previous)])"
    />

    <div class="ad-toolbar">
        <div class="ad-seg" role="group" aria-label="{{ __('admin/stats.period') }}">
            @foreach (\App\Domains\Admin\Support\StatisticsPeriod::KEYS as $key)
                <button type="button" wire:click="pickPeriod('{{ $key }}')" aria-pressed="{{ $period === $key ? 'true' : 'false' }}">{{ __('admin/stats.periods.'.$key) }}</button>
            @endforeach
        </div>

        {{-- Area and car tier: they narrow the figures that come from commutes. --}}
        <div class="ad-row" style="gap:6px">
            <label class="ad-sr" for="st-area">{{ __('admin/stats.filters.area') }}</label>
            <select id="st-area" class="ad-select" style="inline-size:auto" wire:model.live="area" @disabled(! $filtered)>
                <option value="">{{ __('admin/stats.filters.all_areas') }}</option>
                @foreach ($areas as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
            <label class="ad-sr" for="st-tier">{{ __('admin/stats.filters.tier') }}</label>
            <select id="st-tier" class="ad-select" style="inline-size:auto" wire:model.live="tier" @disabled(! $filtered)>
                <option value="">{{ __('admin/stats.filters.all_tiers') }}</option>
                @foreach (\App\Domains\Driver\Enums\VehicleTier::cases() as $option)
                    <option value="{{ $option->value }}">{{ __('admin/stats.filters.tiers.'.$option->value) }}</option>
                @endforeach
            </select>
            @if ($mayExport && $tab !== 'definitions')
                <button type="button" class="ad-btn ad-btn--sm" wire:click="export" title="{{ __('admin/tables.export_hint') }}"><x-admin.icon name="download" size="14" />{{ __('admin/tables.export_csv') }}</button>
                <button type="button" class="ad-btn ad-btn--sm" x-data x-on:click="window.print()"><x-admin.icon name="file" size="14" />{{ __('admin/stats.export_pdf') }}</button>
            @endif
        </div>
    </div>

    @if (! $filtered && ($area !== '' || $tier !== '') && $tab !== 'definitions')
        <p class="ad-hint" style="margin:0 0 8px">{{ __('admin/stats.filters.not_here') }}</p>
    @endif

    <div class="ad-tabs" role="tablist">
        @foreach (\App\Livewire\Admin\Statistics::TABS as $name)
            <button type="button" role="tab" class="ad-tab" aria-selected="{{ $tab === $name ? 'true' : 'false' }}" wire:click="pickTab('{{ $name }}')">{{ __('admin/stats.tabs.'.$name) }}</button>
        @endforeach
    </div>

    <div wire:loading.delay class="ad-muted" style="margin-block-end:8px">{{ __('admin/shell.states.loading') }}…</div>

    {{-- A figure tile with its change against the previous period. --}}
    @php
        $tile = function (string $label, string $value, $now = null, $before = null, ?string $foot = null, bool $lowerIsBetter = false) use ($change) {
            return compact('label', 'value', 'foot') + ['change' => $now === null ? null : $change($now, $before), 'lowerIsBetter' => $lowerIsBetter];
        };
    @endphp

    @if ($tab === 'revenue')
        @php
            $n = $data['now'];
            $b = $data['before'];
            $tiles = [
            $tile(__('admin/stats.revenue.commission'), $egp($n['commission']), $n['commission'], $b['commission']),
            $tile(__('admin/stats.revenue.received'), $egp($n['received']), $n['received'], $b['received']),
            $tile(__('admin/stats.revenue.waiting'), $egp($n['waiting']), null, null, trans_choice('admin/stats.revenue.claims', $n['waitingCount'], ['count' => $n['waitingCount']])),
            $tile(__('admin/stats.revenue.late_fees'), $egp($n['lateFees']), $n['lateFees'], $b['lateFees']),];
        @endphp
        @include('livewire.admin.partials.stat-tiles', ['tiles' => $tiles])

        <div class="ad-grid ad-grid--3" style="margin-block-start:12px">
            <x-admin.card flush>
                <x-admin.stat :label="__('admin/stats.revenue.owed_now')" :value="$egp($n['owedNow'])" money :foot="e(__('admin/stats.revenue.owed_now_note'))" />
            </x-admin.card>
            <x-admin.card flush>
                <x-admin.stat :label="__('admin/stats.revenue.given')" :value="$egp($n['given'])" money :foot="e(__('admin/stats.revenue.written_off', ['amount' => $egp($n['writtenOff'])]))" />
            </x-admin.card>
            <x-admin.card flush class="ad-card--dashed">
                <x-admin.stat :label="__('admin/stats.revenue.fees')" :value="$egp($n['fees'])" money :foot="e(__('admin/stats.revenue.fees_note'))" />
            </x-admin.card>
        </div>

        <x-admin.card :title="__('admin/stats.revenue.weekly_title')" bar style="margin-block-start:12px">
            <p class="ad-hint" style="margin:0 0 10px">{{ __('admin/stats.revenue.weekly_sub') }}</p>
            <x-admin.bar-chart
                :caption="__('admin/stats.revenue.weekly_title')"
                :labels="array_map(fn ($w) => \Carbon\CarbonImmutable::parse($w['week'])->format('j M'), $data['weekly'])"
                :series="[
                    ['name' => __('admin/stats.revenue.commission'), 'values' => array_column($data['weekly'], 'earned')],
                    ['name' => __('admin/stats.revenue.received'), 'values' => array_column($data['weekly'], 'received')],
                ]"
                :format="fn ($v) => number_format($v / 100)"
            />
        </x-admin.card>

    @elseif ($tab === 'trips')
        @php
            $n = $data['now'];
            $b = $data['before'];
            $tiles = [
            $tile(__('admin/stats.trips.gross'), $egp($n['grossValue']), $n['grossValue'], $b['grossValue']),
            $tile(__('admin/stats.trips.driver_earnings'), $egp($n['driverEarnings']), $n['driverEarnings'], $b['driverEarnings']),
            $tile(__('admin/stats.trips.seats'), number_format($n['seatsCompleted']), $n['seatsCompleted'], $b['seatsCompleted']),
            $tile(__('admin/stats.trips.days_completed'), number_format($n['daysCompleted']), $n['daysCompleted'], $b['daysCompleted']),
            $tile(__('admin/stats.trips.days_cancelled'), number_format($n['daysCancelled']), $n['daysCancelled'], $b['daysCancelled'], null, true),
            $tile(__('admin/stats.trips.no_shows'), number_format($n['noShows']), $n['noShows'], $b['noShows'], null, true),
            $tile(__('admin/stats.trips.occupancy'), $n['occupancy'] === null ? '—' : $n['occupancy'].'%', $n['occupancy'], $b['occupancy']),
            $tile(__('admin/stats.trips.take_rate'), $n['grossValue'] > 0 ? round($n['platformShare'] / $n['grossValue'] * 100, 1).'%' : '—'),];
        @endphp
        @include('livewire.admin.partials.stat-tiles', ['tiles' => $tiles])

        <x-admin.card :title="__('admin/stats.trips.daily_title')" bar style="margin-block-start:12px">
            @if (array_sum(array_column($data['daily'], 'seats')) === 0)
                <x-admin.state kind="empty" :title="__('admin/stats.no_data')" />
            @else
                <x-admin.bar-chart
                    :caption="__('admin/stats.trips.daily_title')"
                    :labels="array_map(fn ($d) => \Carbon\CarbonImmutable::parse($d['day'])->format('j M'), $data['daily'])"
                    :series="[['name' => __('admin/stats.trips.seats'), 'values' => array_column($data['daily'], 'seats')]]"
                />
            @endif
        </x-admin.card>

    @elseif ($tab === 'growth')
        @php
            $n = $data['now'];
            $b = $data['before'];
            $tiles = [
            $tile(__('admin/stats.growth.new_members'), number_format($n['newMembers']), $n['newMembers'], $b['newMembers'], __('admin/stats.growth.total', ['count' => number_format($n['membersTotal'])])),
            $tile(__('admin/stats.growth.new_drivers'), number_format($n['newDrivers']), $n['newDrivers'], $b['newDrivers'], __('admin/stats.growth.total', ['count' => number_format($n['driversTotal'])])),
            $tile(__('admin/stats.growth.new_commutes'), number_format($n['newCommutes']), $n['newCommutes'], $b['newCommutes'], __('admin/stats.growth.live', ['count' => number_format($n['commutesLive'])])),];
        @endphp
        @include('livewire.admin.partials.stat-tiles', ['tiles' => $tiles])

        <x-admin.card :title="__('admin/stats.growth.weekly_title')" bar style="margin-block-start:12px">
            <x-admin.bar-chart
                :caption="__('admin/stats.growth.weekly_title')"
                :labels="array_map(fn ($w) => \Carbon\CarbonImmutable::parse($w['week'])->format('j M'), $data['weekly'])"
                :series="[['name' => __('admin/stats.growth.new_members'), 'values' => array_column($data['weekly'], 'members')]]"
            />
        </x-admin.card>

    @elseif ($tab === 'supply')
        @php
            $o = $data['offers'];
            $tiles = [
                $tile(__('admin/stats.supply.published'), number_format($o['published'])),
                $tile(__('admin/stats.supply.seats_offered'), number_format($o['seatsOffered'])),
                $tile(__('admin/stats.supply.unmatched'), number_format($o['unmatched'])),
                $tile(__('admin/stats.supply.waitlisted'), number_format($o['waitlisted'])),
            ];
        @endphp
        @include('livewire.admin.partials.stat-tiles', ['tiles' => $tiles])

        <x-admin.card flush :title="__('admin/stats.supply.pairs_title')" bar style="margin-block-start:12px">
            @if ($data['pairs'] === [])
                <x-admin.state kind="empty" :title="__('admin/stats.no_data')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/stats.supply.pair') }}</th>
                        <th class="ad-end">{{ __('admin/stats.supply.commutes') }}</th>
                        <th class="ad-end">{{ __('admin/stats.supply.offered') }}</th>
                        <th class="ad-end">{{ __('admin/stats.supply.booked') }}</th>
                        <th class="ad-end">{{ __('admin/stats.trips.occupancy') }}</th>
                        <th class="ad-end">{{ __('admin/stats.supply.waiting') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($data['pairs'] as $row)
                        <tr>
                            <td data-label="{{ __('admin/stats.supply.pair') }}"><bdi>{{ $row['pair'] }}</bdi></td>
                            <td data-label="{{ __('admin/stats.supply.commutes') }}" class="ad-end ad-num">{{ $row['commutes'] }}</td>
                            <td data-label="{{ __('admin/stats.supply.offered') }}" class="ad-end ad-num">{{ $row['offered'] }}</td>
                            <td data-label="{{ __('admin/stats.supply.booked') }}" class="ad-end ad-num">{{ $row['taken'] }}</td>
                            <td data-label="{{ __('admin/stats.trips.occupancy') }}" class="ad-end ad-num">{{ $row['occupancy'] === null ? '—' : $row['occupancy'].'%' }}</td>
                            <td data-label="{{ __('admin/stats.supply.waiting') }}" class="ad-end ad-num">{{ $row['waiting'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @elseif ($tab === 'quality')
        @php
            $n = $data['now'];
            $b = $data['before'];
            $pct = fn ($v) => $v === null ? '—' : $v.'%';
            $tiles = [
                $tile(__('admin/stats.quality.no_show_rate'), $pct($n['noShowRate']), $n['noShowRate'], $b['noShowRate'], trans_choice('admin/stats.quality.no_shows', $n['noShows'], ['count' => $n['noShows']]), true),
                $tile(__('admin/stats.quality.runs_cancelled'), number_format($n['runsCancelled']), $n['runsCancelled'], $b['runsCancelled'], null, true),
                $tile(__('admin/stats.quality.reports_per_100'), $n['reportsPer100'] ?? '—', $n['reportsPer100'], $b['reportsPer100'], trans_choice('admin/stats.quality.reports', $n['reports'], ['count' => $n['reports']]), true),
                $tile(__('admin/stats.quality.sos'), number_format($n['sos']), $n['sos'], $b['sos'], null, true),
                $tile(__('admin/stats.quality.avg_driver'), $n['avgDriver'] === null ? '—' : number_format($n['avgDriver'], 2).' ★', null, null, __('admin/stats.quality.today')),
                $tile(__('admin/stats.quality.avg_passenger'), $n['avgPassenger'] === null ? '—' : number_format($n['avgPassenger'], 2).' ★', null, null, __('admin/stats.quality.today')),
                $tile(__('admin/stats.quality.appeals_filed'), number_format($n['appealsFiled']), $n['appealsFiled'], $b['appealsFiled']),
                $tile(__('admin/stats.quality.appeals_approved'), $pct($n['appealsApproved'])),
            ];
        @endphp
        @include('livewire.admin.partials.stat-tiles', ['tiles' => $tiles])

        <x-admin.card :title="__('admin/stats.quality.by_category')" bar style="margin-block-start:12px">
            @if ($n['byCategory'] === [])
                <x-admin.state kind="empty" :title="__('admin/stats.no_data')" />
            @else
                <x-admin.bar-chart
                    :caption="__('admin/stats.quality.by_category')"
                    :labels="array_map(fn ($c) => \Illuminate\Support\Facades\Lang::has('admin.safety.categories.'.$c) ? __('admin.safety.categories.'.$c) : str_replace('_', ' ', $c), array_keys($n['byCategory']))"
                    :series="[['name' => __('admin/stats.quality.reports_label'), 'values' => array_values($n['byCategory'])]]"
                />
            @endif
        </x-admin.card>

    @elseif ($tab === 'team')
        @php
            $hours = fn ($h) => $h === null ? '—' : __('admin/stats.team.hours', ['hours' => $h]);
            $tiles = [
                $tile(__('admin/stats.team.verifications'), number_format($data['verifications'])),
                $tile(__('admin/stats.team.report_median'), $hours($data['reportMedianHours'])),
                $tile(__('admin/stats.team.reports_on_time'), $data['reportsOnTime'] === null ? '—' : $data['reportsOnTime'].'%'),
                $tile(__('admin/stats.team.payment_median'), $hours($data['paymentMedianHours'])),
            ];
        @endphp
        @include('livewire.admin.partials.stat-tiles', ['tiles' => $tiles])

        <x-admin.card flush :title="__('admin/stats.team.people_title')" bar style="margin-block-start:12px">
            @if ($data['people'] === [])
                <x-admin.state kind="empty" :title="__('admin/stats.no_data')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/stats.team.member') }}</th>
                        <th>{{ __('admin/stats.team.role') }}</th>
                        <th class="ad-end">{{ __('admin/stats.team.verifications') }}</th>
                        <th class="ad-end">{{ __('admin/stats.team.reports_closed') }}</th>
                        <th class="ad-end">{{ __('admin/stats.team.payments') }}</th>
                        <th class="ad-end">{{ __('admin/stats.team.sos') }}</th>
                        <th class="ad-end">{{ __('admin/stats.team.all') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($data['people'] as $person)
                        <tr>
                            <td data-label="{{ __('admin/stats.team.member') }}">{{ $person['name'] }}</td>
                            <td data-label="{{ __('admin/stats.team.role') }}">{{ $person['role'] && \Illuminate\Support\Facades\Lang::has('admin/shell.roles.'.$person['role']) ? __('admin/shell.roles.'.$person['role']) : ($person['role'] ?? '—') }}</td>
                            <td data-label="{{ __('admin/stats.team.verifications') }}" class="ad-end ad-num">{{ $person['verifications'] }}</td>
                            <td data-label="{{ __('admin/stats.team.reports_closed') }}" class="ad-end ad-num">{{ $person['reports'] }}</td>
                            <td data-label="{{ __('admin/stats.team.payments') }}" class="ad-end ad-num">{{ $person['payments'] }}</td>
                            <td data-label="{{ __('admin/stats.team.sos') }}" class="ad-end ad-num">{{ $person['sos'] }}</td>
                            <td data-label="{{ __('admin/stats.team.all') }}" class="ad-end ad-num" style="font-weight:700">{{ $person['total'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @elseif ($tab === 'money')
        @php
            $tiles = [
                $tile(__('admin/stats.money.drivers_blocked'), number_format($data['driversBlocked']), null, null, __('admin/stats.quality.today')),
                $tile(__('admin/stats.money.passengers_suspended'), number_format($data['passengersSuspended']), null, null, __('admin/stats.quality.today')),
                $tile(__('admin/stats.money.owing'), number_format($data['owing']), null, null, __('admin/stats.quality.today')),
                $tile(__('admin/stats.team.payment_median'), $data['paymentMedianHours'] === null ? '—' : __('admin/stats.team.hours', ['hours' => $data['paymentMedianHours']])),
            ];
        @endphp
        @include('livewire.admin.partials.stat-tiles', ['tiles' => $tiles])

        <x-admin.card flush :title="__('admin/stats.money.biggest')" bar style="margin-block-start:12px">
            @if ($data['biggest'] === [])
                <x-admin.state kind="empty" :title="__('admin/stats.money.nobody_owes')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/stats.money.person') }}</th>
                        <th>{{ __('admin/stats.money.side') }}</th>
                        <th class="ad-end">{{ __('admin/stats.money.owed') }}</th>
                        <th>{{ __('admin/stats.money.status') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($data['biggest'] as $debt)
                        <tr>
                            <td data-label="{{ __('admin/stats.money.person') }}">
                                @can('member.view')
                                    <a class="ad-link" href="{{ route('admin.members.show', $debt['userId']) }}" wire:navigate><bdi>{{ $debt['name'] }}</bdi></a>
                                @else
                                    <bdi>{{ $debt['name'] }}</bdi>
                                @endcan
                            </td>
                            <td data-label="{{ __('admin/stats.money.side') }}">{{ __('admin/stats.money.sides.'.$debt['side']) }}</td>
                            <td data-label="{{ __('admin/stats.money.owed') }}" class="ad-end ad-num">{{ $egp($debt['owed']) }}</td>
                            <td data-label="{{ __('admin/stats.money.status') }}">
                                <span @class(['ad-chip', 'ad-chip--bad' => $debt['suspended'], 'ad-chip--warn' => ! $debt['suspended']])>{{ $debt['suspended'] ? __('admin/stats.money.suspended') : __('admin/stats.money.owes') }}</span>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </x-admin.card>

    @else
        <x-admin.card :title="__('admin/stats.tabs.definitions')" bar>
            <dl class="ad-kv">
                @foreach (__('admin/stats.definitions') as $term => $meaning)
                    <dt style="font-weight:650;color:var(--fg-default)">{{ __('admin/stats.terms.'.$term) }}</dt>
                    <dd style="font-weight:400">{{ $meaning }}</dd>
                @endforeach
            </dl>
        </x-admin.card>
    @endif
</div>
