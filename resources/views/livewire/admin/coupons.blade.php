{{-- COUPONS (K). Issued coupons, newest first. Issuing happens on Payments, beside the fee it answers. --}}
<div>
    <x-admin.page-head :title="__('admin/money.coupons.title')" :sub="__('admin/money.coupons.sub')">
        @if ($mayIssue)
            <a class="ad-btn ad-btn--primary" href="{{ route('admin.payments') }}" wire:navigate><x-admin.icon name="gift" />{{ __('admin.payments.issue_coupon') }}</a>
        @endif
    </x-admin.page-head>

    <div class="ad-seg" role="group" style="margin-block-end:12px">
        <button type="button" wire:click="$set('view', 'coupons')" aria-pressed="{{ $view === 'coupons' ? 'true' : 'false' }}">{{ __('admin/money.coupons.tab_coupons') }}</button>
        <button type="button" wire:click="$set('view', 'credits')" aria-pressed="{{ $view === 'credits' ? 'true' : 'false' }}">{{ __('admin/money.coupons.tab_credits') }}</button>
    </div>

    @if ($view === 'credits')
        {{-- K04 · Credits added by hand: money off what somebody owes, never paid out. --}}
        <x-admin.card flush>
            @if ($credits->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/money.coupons.no_credits')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/money.when') }}</th>
                        <th>{{ __('admin/money.member') }}</th>
                        <th class="ad-end">{{ __('admin/money.amount') }}</th>
                        <th>{{ __('admin/money.coupons.by') }}</th>
                        <th>{{ __('admin/money.reason') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($credits as $credit)
                        <tr wire:key="credit-{{ $credit->id }}">
                            <td data-label="{{ __('admin/money.when') }}" class="ad-num ad-muted">{{ $credit->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</td>
                            <td data-label="{{ __('admin/money.member') }}"><bdi>{{ $credit->user?->full_name ?? '—' }}</bdi> <span class="ad-chip ad-chip--plain">{{ __('admin.payments.side_'.$credit->side->value) }}</span></td>
                            <td data-label="{{ __('admin/money.amount') }}" class="ad-end ad-num" style="font-weight:700">EGP {{ number_format(abs($credit->amount_piastres) / 100, 2) }}</td>
                            <td data-label="{{ __('admin/money.coupons.by') }}">{{ $credit->createdBy?->name ?? '—' }}</td>
                            <td data-label="{{ __('admin/money.reason') }}" dir="auto">{{ $credit->meta['note'] ?? $credit->reason_code }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <x-rq-pager :page="$credits" />
            @endif
        </x-admin.card>
    @else
    <x-admin.card flush>
        @if ($coupons->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/money.coupons.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/money.when') }}</th>
                        <th>{{ __('admin/money.member') }}</th>
                        <th class="ad-end">{{ __('admin/money.amount') }}</th>
                        <th>{{ __('admin/money.coupons.by') }}</th>
                        <th>{{ __('admin/money.reason') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($coupons as $coupon)
                        <tr wire:key="coupon-{{ $coupon->id }}">
                            <td data-label="{{ __('admin/money.when') }}" class="ad-num ad-muted">{{ $coupon->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</td>
                            <td data-label="{{ __('admin/money.member') }}"><bdi>{{ $coupon->user?->full_name ?? '—' }}</bdi> <span class="ad-chip ad-chip--plain">{{ __('admin.payments.side_'.(is_string($coupon->side) ? $coupon->side : $coupon->side->value)) }}</span></td>
                            <td data-label="{{ __('admin/money.amount') }}" class="ad-end ad-num" style="font-weight:700">EGP {{ number_format($coupon->value_piastres / 100, 2) }}</td>
                            <td data-label="{{ __('admin/money.coupons.by') }}">{{ $coupon->issuedBy?->name ?? '—' }}</td>
                            <td data-label="{{ __('admin/money.reason') }}" dir="auto">{{ $coupon->note }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    <x-rq-pager :page="$coupons" />
    @endif
</div>
