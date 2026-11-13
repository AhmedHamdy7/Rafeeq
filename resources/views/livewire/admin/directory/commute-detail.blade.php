{{-- COMMUTE DETAIL (C02, C03). --}}
@php
    use App\Domains\Commute\Enums\CommuteLocationType;
    use App\Livewire\Admin\Directory\DirectoryPage;

    $stops = $commute->locations->sortBy('sequence')->values();
    $driver = $commute->driverProfile?->user;
    $mask = (int) ($commute->schedule?->days_mask ?? 0);
    $weekdays = collect([1 => 'sat', 2 => 'sun', 4 => 'mon', 8 => 'tue', 16 => 'wed', 32 => 'thu', 64 => 'fri'])->filter(fn ($day, $bit) => ($mask & $bit) !== 0);
    $money = fn ($p) => $p === null ? '—' : __('admin/home.egp', ['amount' => number_format(((int) $p) / 100, 2)]);
@endphp
<div>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px">
        <a class="ad-link" href="{{ route('admin.commutes') }}" wire:navigate>{{ __('admin/directory.commutes.title') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <span class="ad-code">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($commute->id, -6)) }}</span>
    </nav>

    <x-admin.page-head :title="($stops->first()?->address ?? '—').' → '.($stops->last()?->address ?? '—')">
        @if ($mayResume)
            <button type="button" class="ad-btn ad-btn--primary" wire:click="startResume">{{ __('admin/records.commute.resume') }}</button>
        @endif
    </x-admin.page-head>

    @if ($done)
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ $done }}</p>
    @endif

    @if ($resuming)
        @include('livewire.admin.directory._reason-form', ['title' => __('admin/records.commute.resume'), 'hint' => __('admin/records.commute.resume_hint'), 'submit' => 'resume', 'confirm' => __('admin/records.commute.resume'), 'cancel' => '$set(\'resuming\', false)'])
    @endif

    <div class="ad-split">
        <div class="ad-stack">
            <x-admin.card :title="__('admin/records.commute.summary')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin/directory.status') }}</dt><dd><span class="ad-chip ad-chip--{{ DirectoryPage::tone($commute->status->value) }}">{{ __('admin/directory.statuses.'.$commute->status->value) }}</span>@if ($commute->paused_reason) <span class="ad-muted">· {{ __('admin/records.commute.paused.'.$commute->paused_reason->value) }}</span>@endif</dd></div>
                    <div><dt>{{ __('admin/directory.driver') }}</dt><dd>@if ($driver)<a class="ad-link" href="{{ route('admin.members.show', $driver->id) }}" wire:navigate><bdi>{{ $driver->full_name }}</bdi></a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/directory.car') }}</dt><dd>@if ($commute->vehicle)<a class="ad-link" href="{{ route('admin.vehicles.show', $commute->vehicle->id) }}" wire:navigate><bdi>{{ $commute->vehicle->make }} {{ $commute->vehicle->model }}</bdi> · <span class="ad-mono"><bdi>{{ $commute->vehicle->plate_number }}</bdi></span></a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/records.commute.audience') }}</dt><dd>{{ $commute->audience?->value === 'women_only' ? __('admin.trips.women_only') : __('admin/records.commute.anyone') }}</dd></div>
                    <div><dt>{{ __('admin/records.commute.departs') }}</dt><dd class="ad-num">{{ substr((string) $commute->schedule?->departure_time, 0, 5) ?: '—' }} · {{ $weekdays->map(fn ($day) => __('admin/records.days.'.$day))->implode(' ') ?: '—' }}</dd></div>
                    <div><dt>{{ __('admin/directory.seats') }}</dt><dd class="ad-num">{{ $commute->seats_total }}</dd></div>
                    <div><dt>{{ __('admin/records.commute.seat_price') }}</dt><dd class="ad-num">{{ $money($commute->seat_price_piastres) }} <span class="ad-muted">· {{ __('admin/records.commute.driver_share', ['amount' => $money($commute->driver_share_piastres)]) }}</span></dd></div>
                    <div><dt>{{ __('admin/records.commute.waiting') }}</dt><dd class="ad-num">{{ $commute->waiting }} · {{ __('admin/records.commute.pending', ['count' => $commute->pending]) }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card flush :title="__('admin/records.commute.stops')" bar>
                <ol class="ad-timeline" style="padding:12px 16px">
                    @foreach ($stops as $stop)
                        <li class="ad-timeline__item">
                            <span class="ad-timeline__time">{{ __('admin/records.commute.stop_types.'.$stop->type->value) }}</span>
                            <span class="ad-timeline__body"><bdi>{{ $stop->address ?? '—' }}</bdi>@if (! $stop->is_exact) <span class="ad-muted">· {{ __('admin/records.commute.approximate') }}</span>@endif</span>
                        </li>
                    @endforeach
                </ol>
            </x-admin.card>
        </div>

        <div class="ad-stack">
            <x-admin.card flush :title="__('admin/records.commute.days')" bar>
                @if ($days->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/records.none')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($days as $day)
                        <tr wire:key="cd-{{ $day->id }}">
                            <td><a class="ad-link ad-num" href="{{ route('admin.trip-days.show', $day->id) }}" wire:navigate>{{ $day->trip_date?->format('D j M') }}</a></td>
                            <td class="ad-num">{{ $day->seats_taken }} / {{ $day->seats_total }}</td>
                            <td><span class="ad-chip ad-chip--{{ DirectoryPage::tone($day->status->value) }}">{{ __('admin/directory.statuses.'.$day->status->value) }}</span></td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>

            @include('livewire.admin.directory._history')
        </div>
    </div>
</div>
