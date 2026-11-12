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
    </div>

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
        @php($n = $data['now'])
        @php($b = $data['before'])
        @php
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
        @php($n = $data['now'])
        @php($b = $data['before'])
        @php
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
        @php($n = $data['now'])
        @php($b = $data['before'])
        @php
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
