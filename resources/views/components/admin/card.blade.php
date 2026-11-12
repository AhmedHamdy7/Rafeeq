{{--
    A surface. `title` draws the header row; `bar` gives the header its own band with a
    line under it (tables); `tone="alert"` is the red card the home page uses for SOS.
    Anything in the `actions` slot sits at the end of the header row.
--}}
@props(['title' => null, 'bar' => false, 'tone' => null, 'flush' => false])
<section {{ $attributes->class(['ad-card', 'ad-card--alert' => $tone === 'alert', 'ad-card--topline-bad' => $tone === 'topline-bad']) }}>
    @if ($title || isset($actions))
        <header @class(['ad-card__head', 'ad-card__head--bar' => $bar])>
            @if ($title)
                <h2 class="ad-card__title">{{ $title }}</h2>
            @endif
            {{ $actions ?? '' }}
        </header>
    @endif
    @if ($flush)
        {{ $slot }}
    @else
        <div class="ad-card__body">{{ $slot }}</div>
    @endif
</section>
