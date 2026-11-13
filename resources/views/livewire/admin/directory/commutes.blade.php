{{-- COMMUTES (C). Read-only. --}}
@php use App\Domains\Commute\Enums\CommuteLocationType; use App\Livewire\Admin\Directory\DirectoryPage; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.commutes.title')" :sub="trans_choice('admin/directory.count', $commutes->total(), ['count' => $commutes->total()])" />
    @include('livewire.admin.directory._tabs')
    <x-admin.card flush>
        @if ($commutes->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/directory.route') }}</th>
                        <th>{{ __('admin/directory.driver') }}</th>
                        <th>{{ __('admin/directory.car') }}</th>
                        <th class="ad-end">{{ __('admin/directory.seats') }}</th>
                        <th>{{ __('admin/directory.status') }}</th>
                        <th>{{ __('admin/directory.created') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($commutes as $commute)
                        @php($origin = $commute->locations->firstWhere('type', CommuteLocationType::Origin))
                        @php($destination = $commute->locations->firstWhere('type', CommuteLocationType::Destination))
                        <tr wire:key="commute-{{ $commute->id }}">
                            <td data-label="{{ __('admin/directory.route') }}">
                                <a class="ad-link" href="{{ route('admin.commutes.show', $commute->id) }}" wire:navigate><bdi>{{ $origin?->address ?? '—' }}</bdi> <x-admin.icon name="chevron" size="12" class="ad-flip ad-subtle" /> <bdi>{{ $destination?->address ?? '—' }}</bdi></a>
                                @if ($commute->audience?->value === 'women_only')
                                    <span class="ad-chip ad-chip--plain">{{ __('admin.trips.women_only') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('admin/directory.driver') }}"><bdi>{{ $commute->driverProfile?->user?->full_name ?? '—' }}</bdi></td>
                            <td data-label="{{ __('admin/directory.car') }}"><bdi>{{ $commute->vehicle?->make }} {{ $commute->vehicle?->model }}</bdi></td>
                            <td data-label="{{ __('admin/directory.seats') }}" class="ad-end ad-num">{{ $commute->seats_total }}</td>
                            <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($commute->status->value) }}">{{ __('admin/directory.statuses.'.$commute->status->value) }}</span></td>
                            <td data-label="{{ __('admin/directory.created') }}" class="ad-muted ad-num">{{ $commute->created_at?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$commutes" />
</div>
