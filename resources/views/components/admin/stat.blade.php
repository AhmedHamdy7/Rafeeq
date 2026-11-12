{{--
    A queue or number tile: label · big number · status chip · footnote.
    With `href` the whole tile is a link. `locked` adds the role tag the design shows on a
    tile the current role can see but not act on.
--}}
@props(['label', 'value', 'href' => null, 'chip' => null, 'chipTone' => 'ok', 'foot' => null, 'locked' => null, 'money' => false])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if ($href) href="{{ $href }}" wire:navigate @endif {{ $attributes->class(['ad-stat']) }}>
    <span class="ad-stat__label">
        <span style="flex:1">{{ $label }}</span>
        @if ($locked)
            <span class="ad-role-tag"><x-admin.icon name="lock" size="12" />{{ $locked }}</span>
        @endif
    </span>
    <span class="ad-stat__row">
        <span @class(['ad-stat__value', 'ad-stat__value--money' => $money])>{{ $value }}</span>
        @if ($chip)
            <span class="ad-chip ad-chip--{{ $chipTone }}">{{ $chip }}</span>
        @endif
        {{ $slot }}
    </span>
    @if ($foot)
        <span class="ad-stat__foot">{!! $foot !!}</span>
    @endif
</{{ $tag }}>
