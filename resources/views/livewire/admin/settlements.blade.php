{{-- WEEKLY SETTLEMENTS (W). One settlement week (Sat–Fri, Cairo): totals, then one row per driver. Read-only. --}}
@php($egp = fn (int $piastres) => 'EGP '.number_format($piastres / 100, 2))
<div>
    <x-admin.page-head
        :title="__('admin/money.settlements.title')"
        :sub="__('admin/money.settlements.week', ['from' => $weekStart->format('D j M'), 'to' => $weekStart->addDays(6)->format('D j M Y')])"
    >
        <div class="ad-seg">
            <button type="button" wire:click="previous" aria-pressed="false"><x-admin.icon name="chevron" size="12" class="ad-pager__back" /> {{ __('admin/money.settlements.previous') }}</button>
            <button type="button" wire:click="next" aria-pressed="false" @disabled($isCurrent)>{{ __('admin/money.settlements.next') }} <x-admin.icon name="chevron" size="12" class="ad-flip" /></button>
        </div>
    </x-admin.page-head>

    <div class="ad-grid ad-grid--4">
        <x-admin.card flush><x-admin.stat :label="__('admin/money.settlements.commission')" :value="$egp($totals['commission'])" money /></x-admin.card>
        <x-admin.card flush><x-admin.stat :label="__('admin/money.settlements.received')" :value="$egp($totals['paid'])" money /></x-admin.card>
        <x-admin.card flush><x-admin.stat :label="__('admin/money.settlements.owed_now')" :value="$egp($totals['owedNow'])" money /></x-admin.card>
        <x-admin.card flush :tone="$totals['blocked'] > 0 ? 'topline-bad' : null"><x-admin.stat :label="__('admin/money.settlements.blocked')" :value="$totals['blocked']" /></x-admin.card>
    </div>

    <div class="ad-section-label">{{ __('admin/money.settlements.drivers') }}</div>
    <x-admin.card flush>
        @if ($rows->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/money.settlements.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/money.member') }}</th>
                        <th class="ad-end">{{ __('admin/money.settlements.commission') }}</th>
                        <th class="ad-end">{{ __('admin/money.settlements.received') }}</th>
                        <th class="ad-end">{{ __('admin/money.settlements.owed_now') }}</th>
                        <th>{{ __('admin/money.settlements.state') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="settlement-{{ $row['id'] }}" @class(['ad-row--flagged' => $row['blocked']])>
                            <td data-label="{{ __('admin/money.member') }}">
                                @can('member.view')
                                    <a class="ad-link" href="{{ route('admin.members', ['member' => $row['id']]) }}" wire:navigate><bdi>{{ $row['name'] ?? '—' }}</bdi></a>
                                @else
                                    <bdi>{{ $row['name'] ?? '—' }}</bdi>
                                @endcan
                            </td>
                            <td data-label="{{ __('admin/money.settlements.commission') }}" class="ad-end ad-num">{{ $egp($row['commission']) }}</td>
                            <td data-label="{{ __('admin/money.settlements.received') }}" class="ad-end ad-num">{{ $egp($row['paid']) }}</td>
                            <td data-label="{{ __('admin/money.settlements.owed_now') }}" class="ad-end ad-num" style="font-weight:700">{{ $egp($row['owesNow']) }}</td>
                            <td data-label="{{ __('admin/money.settlements.state') }}">
                                @if ($row['blocked'])
                                    <span class="ad-chip ad-chip--bad">{{ __('admin/money.settlements.state_blocked') }}</span>
                                @elseif ($row['owesNow'] > 0)
                                    <span class="ad-chip ad-chip--warn">{{ __('admin/money.settlements.state_owes') }}</span>
                                @else
                                    <span class="ad-chip ad-chip--ok">{{ __('admin/money.settlements.state_clear') }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
</div>
