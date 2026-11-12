{{-- DEMANDS (Q). What passengers asked for. Read-only. --}}
@php use App\Livewire\Admin\Directory\DirectoryPage; use App\Domains\Shared\ValueObjects\DaysMask; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.demands.title')" :sub="trans_choice('admin/directory.count', $demands->total(), ['count' => $demands->total()]).' · '.__('admin/directory.demands.sub')" />
    @include('livewire.admin.directory._tabs')
    <x-admin.card flush>
        @if ($demands->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/directory.route') }}</th>
                        <th>{{ __('admin/directory.passenger') }}</th>
                        <th>{{ __('admin/directory.days') }}</th>
                        <th>{{ __('admin/directory.arrive') }}</th>
                        <th>{{ __('admin/directory.status') }}</th>
                        <th>{{ __('admin/directory.created') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($demands as $demand)
                        <tr wire:key="demand-{{ $demand->id }}">
                            <td data-label="{{ __('admin/directory.route') }}">
                                <bdi>{{ $demand->origin_label ?? '—' }}</bdi> <x-admin.icon name="chevron" size="12" class="ad-flip ad-subtle" /> <bdi>{{ $demand->destination_label ?? '—' }}</bdi>
                                @if ($demand->women_only)
                                    <span class="ad-chip ad-chip--plain">{{ __('admin.trips.women_only') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('admin/directory.passenger') }}"><bdi>{{ $demand->passenger?->full_name ?? '—' }}</bdi></td>
                            <td data-label="{{ __('admin/directory.days') }}">
                                @foreach ([DaysMask::SATURDAY => 'sat', DaysMask::SUNDAY => 'sun', DaysMask::MONDAY => 'mon', DaysMask::TUESDAY => 'tue', DaysMask::WEDNESDAY => 'wed', DaysMask::THURSDAY => 'thu', DaysMask::FRIDAY => 'fri'] as $bit => $name)
                                    @if (($demand->days_mask & $bit) > 0)<span class="ad-code">{{ __('admin/directory.weekdays.'.$name) }}</span>@endif
                                @endforeach
                            </td>
                            <td data-label="{{ __('admin/directory.arrive') }}" class="ad-num">{{ \Illuminate\Support\Str::substr((string) $demand->preferred_arrival_start, 0, 5) }}@if ($demand->preferred_arrival_end)–{{ \Illuminate\Support\Str::substr((string) $demand->preferred_arrival_end, 0, 5) }}@endif</td>
                            <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ DirectoryPage::tone($demand->status->value) }}">{{ __('admin/directory.statuses.'.$demand->status->value) }}</span></td>
                            <td data-label="{{ __('admin/directory.created') }}" class="ad-muted ad-num">{{ $demand->created_at?->timezone('Africa/Cairo')->format('j M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$demands" />
</div>
