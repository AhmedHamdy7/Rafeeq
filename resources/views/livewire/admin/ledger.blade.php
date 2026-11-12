{{-- LEDGER (G). Every money line, newest first, with the balance it left. Read-only. --}}
<div>
    <x-admin.page-head :title="__('admin/money.ledger.title')" :sub="__('admin/money.ledger.sub')" />

    <div class="ad-toolbar">
        <select class="ad-select" style="inline-size:auto" wire:model.live="side" aria-label="{{ __('admin/money.side') }}">
            <option value="">{{ __('admin/money.both_sides') }}</option>
            <option value="driver">{{ __('admin.payments.side_driver') }}</option>
            <option value="passenger">{{ __('admin.payments.side_passenger') }}</option>
        </select>
        <select class="ad-select" style="inline-size:auto" wire:model.live="type" aria-label="{{ __('admin/money.type') }}">
            <option value="">{{ __('admin/money.all_types') }}</option>
            @foreach ($types as $entryType)
                <option value="{{ $entryType->value }}">{{ __('admin/money.types.'.$entryType->value) }}</option>
            @endforeach
        </select>
        <label class="ad-search" style="flex:1;min-inline-size:200px">
            <x-admin.icon name="search" />
            <input type="search" wire:model.live.debounce.400ms="member" placeholder="{{ __('admin/money.member_search') }}" aria-label="{{ __('admin/money.member_search') }}">
        </label>
    </div>

    <x-admin.card flush>
        @if ($entries->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/money.ledger.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/money.when') }}</th>
                        <th>{{ __('admin/money.member') }}</th>
                        <th>{{ __('admin/money.type') }}</th>
                        <th>{{ __('admin/money.reason') }}</th>
                        <th class="ad-end">{{ __('admin/money.amount') }}</th>
                        <th class="ad-end">{{ __('admin/money.balance') }}</th>
                        <th>{{ __('admin/money.week') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}">
                            <td data-label="{{ __('admin/money.when') }}" class="ad-num ad-muted" style="white-space:nowrap">{{ $entry->created_at?->timezone('Africa/Cairo')->format('j M Y H:i') }}</td>
                            <td data-label="{{ __('admin/money.member') }}">
                                <bdi>{{ $entry->user?->full_name ?? '—' }}</bdi>
                                <span class="ad-chip ad-chip--plain">{{ __('admin.payments.side_'.$entry->side->value) }}</span>
                            </td>
                            <td data-label="{{ __('admin/money.type') }}"><span class="ad-chip ad-chip--plain">{{ __('admin/money.types.'.$entry->type->value) }}</span></td>
                            <td data-label="{{ __('admin/money.reason') }}">
                                <span class="ad-code">{{ $entry->reason_code }}</span>
                                @if ($entry->createdBy)
                                    <span class="ad-muted" style="font-size:12px">· {{ $entry->createdBy->name }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('admin/money.amount') }}" class="ad-end ad-num" style="font-weight:700;color:{{ $entry->amount_piastres < 0 ? 'var(--success-600)' : 'var(--fg-default)' }}">
                                {{ $entry->amount_piastres < 0 ? '−' : '+' }}{{ number_format(abs($entry->amount_piastres) / 100, 2) }}
                            </td>
                            <td data-label="{{ __('admin/money.balance') }}" class="ad-end ad-num">{{ number_format($entry->balance_after_piastres / 100, 2) }}</td>
                            <td data-label="{{ __('admin/money.week') }}" class="ad-num ad-muted">{{ $entry->week_of?->format('j M') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    <x-rq-pager :page="$entries" />
</div>
