{{-- BOOKING DETAIL (B03, B04). --}}
@php
    use App\Livewire\Admin\Directory\DirectoryPage;

    $trip = $booking->scheduledTrip;
    $driver = $booking->driverProfile?->user;
    $egp = fn ($p) => __('admin/home.egp', ['amount' => number_format(((int) $p) / 100, 2)]);
    $person = fn ($user) => $user === null ? '—' : ($mayOpenMembers ? '<a class="ad-link" href="'.e(route('admin.members.show', $user->id)).'" wire:navigate><bdi>'.e($user->full_name).'</bdi></a>' : '<bdi>'.e($user->full_name).'</bdi>');
@endphp
<div>
    <nav class="ad-muted" style="font-size:12.5px;margin-block-end:6px">
        <a class="ad-link" href="{{ route('admin.bookings') }}" wire:navigate>{{ __('admin/directory.bookings.title') }}</a>
        <x-admin.icon name="chevron" size="12" class="ad-flip" />
        <span class="ad-code">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($booking->id, -6)) }}</span>
    </nav>

    <x-admin.page-head :title="__('admin/records.booking.title')" :sub="$trip?->trip_date?->format('D j M Y')">
        @if ($mayCancel)
            <button type="button" class="ad-btn ad-btn--dangerline" wire:click="startCancel">{{ __('admin/records.booking.cancel') }}</button>
        @endif
    </x-admin.page-head>

    @if ($done)
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ $done }}</p>
    @endif

    @if ($cancelling)
        @include('livewire.admin.directory._reason-form', ['title' => __('admin/records.booking.cancel'), 'hint' => __('admin/records.booking.cancel_hint'), 'submit' => 'cancel', 'confirm' => __('admin/records.booking.cancel'), 'cancel' => '$set(\'cancelling\', false)', 'danger' => true])
    @endif

    <div class="ad-split">
        <div class="ad-stack">
            <x-admin.card :title="__('admin/records.booking.seat')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin/directory.status') }}</dt><dd><span class="ad-chip ad-chip--{{ DirectoryPage::tone($booking->status->value) }}">{{ __('admin/directory.statuses.'.$booking->status->value) }}</span>@if ($booking->cancelled_reason) <span class="ad-muted">· <bdi>{{ \Illuminate\Support\Facades\Lang::has('admin/records.booking.reasons.'.$booking->cancelled_reason) ? __('admin/records.booking.reasons.'.$booking->cancelled_reason) : $booking->cancelled_reason }}</bdi></span>@endif</dd></div>
                    <div><dt>{{ __('admin/records.trip.passenger') }}</dt><dd>{!! $person($booking->passenger) !!}</dd></div>
                    <div><dt>{{ __('admin/directory.driver') }}</dt><dd>{!! $person($driver) !!}</dd></div>
                    <div><dt>{{ __('admin/records.booking.day') }}</dt><dd>@if ($trip)<a class="ad-link ad-num" href="{{ route('admin.trip-days.show', $trip->id) }}" wire:navigate>{{ $trip->trip_date?->format('D j M') }} · {{ $trip->departure_at?->timezone('Africa/Cairo')->format('H:i') }}</a>@else — @endif</dd></div>
                    <div><dt>{{ __('admin/records.booking.pickup') }}</dt><dd><bdi>{{ $booking->pickup_label ?? '—' }}</bdi></dd></div>
                    <div><dt>{{ __('admin/records.booking.dropoff') }}</dt><dd><bdi>{{ $booking->dropoff_label ?? '—' }}</bdi></dd></div>
                    <div><dt>{{ __('admin/directory.seats') }}</dt><dd class="ad-num">{{ $booking->seats_reserved }}</dd></div>
                    <div><dt>{{ __('admin/records.trip.attendance') }}</dt><dd>{{ $booking->attendance ? __('admin/records.attendance.'.$booking->attendance->status->value) : '—' }}</dd></div>
                </dl>
            </x-admin.card>

            <x-admin.card :title="__('admin/records.booking.money')" bar>
                <dl class="ad-facts" style="margin:0">
                    <div><dt>{{ __('admin/records.trip.price') }}</dt><dd class="ad-num">{{ $egp($booking->price_snapshot_piastres) }}</dd></div>
                    <div><dt>{{ __('admin/records.trip.commission') }}</dt><dd class="ad-num">{{ $egp($booking->platform_fee_snapshot_piastres) }}</dd></div>
                    <div><dt>{{ __('admin/records.trip.driver') }}</dt><dd class="ad-num">{{ $egp($booking->driver_amount_snapshot_piastres) }}</dd></div>
                </dl>
                <p class="ad-hint" style="margin:10px 0 0">{{ __('admin/records.booking.frozen') }}</p>
            </x-admin.card>

            <x-admin.card flush :title="__('admin/records.booking.fees')" bar>
                @if ($warnings->isEmpty() && ($entries === null || $entries->isEmpty()))
                    <x-admin.state kind="empty" :title="__('admin/records.booking.no_fees')" />
                @else
                    <table class="ad-table ad-table--dense"><tbody>
                    @foreach ($warnings as $warning)
                        <tr wire:key="w-{{ $warning->id }}">
                            <td>{{ __('admin/records.warnings.'.$warning->kind->value) }} <span class="ad-muted">#{{ $warning->ordinal }}</span></td>
                            <td class="ad-num">{{ $warning->fee_piastres > 0 ? $egp($warning->fee_piastres) : '—' }}</td>
                            <td><span class="ad-chip">{{ __('admin/records.fee_status.'.($warning->fee_status?->value ?? 'none')) }}</span>@if ($warning->disputed_at) <span class="ad-chip ad-chip--warn">{{ __('admin/records.disputed') }}</span>@endif</td>
                        </tr>
                    @endforeach
                    @foreach ($entries ?? [] as $entry)
                        <tr wire:key="e-{{ $entry->id }}">
                            <td><span class="ad-code">{{ $entry->type->value }}</span> <span class="ad-muted">· {{ __('admin/records.sides.'.$entry->side->value) }}</span></td>
                            <td class="ad-num">{{ $egp($entry->amount_piastres) }}</td>
                            <td class="ad-muted ad-num">{{ $entry->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody></table>
                @endif
            </x-admin.card>
        </div>

        <div class="ad-stack">
            <x-admin.card flush :title="__('admin/records.booking.events')" bar>
                @if ($booking->events->isEmpty())
                    <x-admin.state kind="empty" :title="__('admin/records.none')" />
                @else
                    <ol class="ad-timeline" style="padding:12px 16px">
                        @foreach ($booking->events->sortBy('created_at') as $event)
                            <li class="ad-timeline__item">
                                <span class="ad-timeline__time ad-num">{{ $event->created_at?->timezone('Africa/Cairo')->format('j M H:i') }}</span>
                                <span class="ad-timeline__body"><strong>{{ str_replace('_', ' ', (string) $event->event_type?->value) }}</strong> <span class="ad-muted" style="font-size:12px">· {{ $event->actor_type?->value }}</span></span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </x-admin.card>

            @include('livewire.admin.directory._history')
        </div>
    </div>
</div>
