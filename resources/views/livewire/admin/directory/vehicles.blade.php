{{-- VEHICLES (E). Every car, by state; search by plate or driver. Read-only. --}}
@php use App\Livewire\Admin\Directory\DirectoryPage; use Illuminate\Support\Facades\Lang; use Illuminate\Support\Str; @endphp
<div>
    <x-admin.page-head :title="__('admin/directory.vehicles.title')" :sub="trans_choice('admin/directory.count', $vehicles->total(), ['count' => $vehicles->total()])" />
    @include('livewire.admin.directory._tabs')
    <div class="ad-toolbar">
        <label class="ad-search" style="inline-size:min(420px,100%)">
            <x-admin.icon name="search" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="{{ __('admin/directory.vehicles.search') }}" aria-label="{{ __('admin/directory.vehicles.search') }}">
        </label>
    </div>
    <x-admin.card flush>
        @if ($vehicles->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/directory.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/directory.car') }}</th>
                        <th>{{ __('admin/directory.vehicles.plate') }}</th>
                        <th>{{ __('admin/directory.driver') }}</th>
                        <th class="ad-end">{{ __('admin/directory.seats') }}</th>
                        <th>{{ __('admin/directory.vehicles.tier') }}</th>
                        <th>{{ __('admin/directory.status') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($vehicles as $vehicle)
                        @php($state = $vehicle->verification_status->value)
                        <tr wire:key="vehicle-{{ $vehicle->id }}">
                            <td data-label="{{ __('admin/directory.car') }}"><a class="ad-link" href="{{ route('admin.vehicles.show', $vehicle->id) }}" wire:navigate><bdi>{{ $vehicle->make }} {{ $vehicle->model }}</bdi></a> <span class="ad-muted">· {{ $vehicle->year }} · <bdi>{{ $vehicle->colour }}</bdi></span></td>
                            <td data-label="{{ __('admin/directory.vehicles.plate') }}"><span class="ad-mono"><bdi>{{ $vehicle->plate_number }}</bdi></span></td>
                            <td data-label="{{ __('admin/directory.driver') }}">
                                @if ($vehicle->driverProfile?->user)
                                    @can('member.view')
                                        <a class="ad-link" href="{{ route('admin.members.show', $vehicle->driverProfile->user_id) }}" wire:navigate><bdi>{{ $vehicle->driverProfile->user->full_name }}</bdi></a>
                                    @else
                                        <bdi>{{ $vehicle->driverProfile->user->full_name }}</bdi>
                                    @endcan
                                @else
                                    —
                                @endif
                                @if ($vehicle->is_active)<span class="ad-chip ad-chip--brand ad-chip--plain">{{ __('admin.details.active_car') }}</span>@endif
                            </td>
                            <td data-label="{{ __('admin/directory.seats') }}" class="ad-end ad-num">{{ $vehicle->approved_seats ?? $vehicle->seats }}</td>
                            <td data-label="{{ __('admin/directory.vehicles.tier') }}">{{ $vehicle->tier ? (Lang::has('admin.details.values.'.$vehicle->tier->value) ? __('admin.details.values.'.$vehicle->tier->value) : Str::headline($vehicle->tier->value)) : '—' }}@if ($vehicle->has_ac) · AC @endif</td>
                            <td data-label="{{ __('admin/directory.status') }}"><span class="ad-chip ad-chip--{{ ['approved' => 'ok', 'pending' => 'warn', 'rejected' => 'bad', 'suspended' => 'bad'][$state] ?? 'plain' }}">{{ __('admin/directory.statuses.'.$state) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
    <x-rq-pager :page="$vehicles" />
</div>
