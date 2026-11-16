{{--
    A Google map: markers and lines, fitted to what it shows. Renders nothing while the maps
    are off (AdminMaps), so a page that uses it looks exactly as it did before.

    markers: list of {lat, lng, label?, title?, kind?}  kind: origin · pickup · dropoff · destination · car
    lines:   list of {encoded?|points?, kind?}          kind: route (planned) · trail (GPS, dashed)
--}}
@props(['markers' => [], 'lines' => [], 'height' => 320, 'label' => ''])
@if (\App\Domains\Admin\Support\AdminMaps::enabled() && ($markers !== [] || $lines !== []))
    @php($payload = ['markers' => array_values($markers), 'lines' => array_values($lines)])
    <div {{ $attributes->class(['ad-map']) }} style="block-size:{{ (int) $height }}px" role="img" aria-label="{{ $label }}"
         data-failed="{{ __('admin/shell.map_failed') }}"
         wire:ignore wire:key="map-{{ md5(json_encode($payload)) }}"
         x-data x-init="window.rafeeqMap && window.rafeeqMap($el, @js($payload))"></div>
@endif
