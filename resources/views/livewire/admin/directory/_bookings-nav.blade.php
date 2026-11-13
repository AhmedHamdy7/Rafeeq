{{-- Bookings and requests (B01, B02, B05, B06): one section, four lists. --}}
<div class="ad-seg" role="navigation" aria-label="{{ __('admin/directory.bookings.title') }}" style="margin-block-end:12px">
    @foreach (['admin.bookings' => 'bookings', 'admin.seat-requests' => 'seat_requests', 'admin.seat-offers' => 'seat_offers', 'admin.change-proposals' => 'change_proposals'] as $navRoute => $navKey)
        <a href="{{ route($navRoute) }}" wire:navigate aria-pressed="{{ request()->routeIs($navRoute) ? 'true' : 'false' }}">{{ __('admin/directory.booking_nav.'.$navKey) }}</a>
    @endforeach
</div>
