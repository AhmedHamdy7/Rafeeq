{{-- CHANGE PROPOSALS (B06). Read-only. --}}
@php use App\Livewire\Admin\Directory\DirectoryPage; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.bookings.title')" :sub="trans_choice('admin/directory.count', $proposals->total(), ['count' => $proposals->total()])" />
    @include('livewire.admin.directory._bookings-nav')
    @include('livewire.admin.directory._tabs')
    <x-admin.card flush>
        @if ($proposals->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                <thead><tr>
                    <th>{{ __('admin/directory.driver') }}</th>
                    <th>{{ __('admin/directory.day') }}</th>
                    <th>{{ __('admin/directory.change') }}</th>
                    <th class="ad-end">{{ __('admin/directory.answers') }}</th>
                    <th>{{ __('admin/directory.status') }}</th>
                    <th>{{ __('admin/directory.created') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($proposals as $proposal)
                    <tr wire:key="cp-{{ $proposal->id }}">
                        <td data-label="{{ __('admin/directory.driver') }}">@if ($proposal->commuteOffer)<a class="ad-link" href="{{ route('admin.commutes.show', $proposal->commuteOffer->id) }}" wire:navigate><bdi>{{ $proposal->commuteOffer->driverProfile?->user?->full_name ?? '—' }}</bdi></a>@else — @endif</td>
                        <td data-label="{{ __('admin/directory.day') }}" class="ad-num">{{ $proposal->scheduledTrip?->trip_date?->format('D j M') ?? '—' }} <span class="ad-muted">· {{ __('admin/directory.scopes.'.$proposal->scope?->value) }}</span></td>
                        <td data-label="{{ __('admin/directory.change') }}"><span class="ad-muted" style="font-size:12.5px">{{ collect((array) $proposal->changes)->keys()->map(fn ($key) => str_replace('_', ' ', \Illuminate\Support\Str::snake((string) $key)))->implode(', ') ?: '—' }}</span></td>
                        <td data-label="{{ __('admin/directory.answers') }}" class="ad-end ad-num">{{ $proposal->approvals_count }}</td>
                        <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($proposal->status->value) }}">{{ __('admin/directory.statuses.'.$proposal->status->value) }}</span></td>
                        <td data-label="{{ __('admin/directory.created') }}" class="ad-muted ad-num">{{ $proposal->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$proposals" />
</div>
