{{--
    A bar chart drawn as inline SVG: no library, nothing the CSP has to allow.

    One or two series (validated palette: teal #0d9488, amber #d97706 — passes lightness,
    chroma, colour-blind separation and contrast in both themes). Bars rise from a zero
    baseline with 4px rounded tops, a 2px gap between neighbours, a recessive grid, one
    axis. Each bar carries a <title> (hover tooltip); the same numbers are in a table under
    "Show as table", so nothing is colour-only and a screen reader gets the data.

    <x-admin.bar-chart :labels="[...]" :series="[['name' => '…', 'values' => [...]], …]" :format="fn ($v) => …" />
--}}
@props(['labels', 'series', 'format' => null, 'height' => 200, 'caption' => ''])
@php
    $format ??= fn ($value) => number_format($value);
    $colours = ['var(--chart-1)', 'var(--chart-2)'];
    $max = max(1, ...array_map(fn ($s) => max([0, ...$s['values']]), $series));
    // A round top for the axis: 1, 2 or 5 × a power of ten.
    $magnitude = 10 ** floor(log10($max));
    $top = collect([1, 2, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($v) => $v >= $max);
    $width = 640;
    $left = 44;
    $bottom = 22;
    $plotH = $height - $bottom - 8;
    $plotW = $width - $left - 4;
    $groups = max(1, count($labels));
    $groupW = $plotW / $groups;
    $barW = max(2, min(28, ($groupW - 8) / count($series) - 2));
    $y = fn ($value) => 8 + $plotH - ($value / $top) * $plotH;
    $every = (int) ceil($groups / 12); // at most ~12 axis labels
@endphp
<figure class="ad-chart" {{ $attributes }}>
    <svg viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $caption }}" direction="ltr" style="inline-size:100%;block-size:auto">
        @foreach ([0, 0.5, 1] as $step)
            @php($gy = $y($top * $step))
            <line x1="{{ $left }}" x2="{{ $width }}" y1="{{ $gy }}" y2="{{ $gy }}" class="ad-chart__grid" />
            <text x="{{ $left - 6 }}" y="{{ $gy + 4 }}" text-anchor="end" class="ad-chart__tick">{{ $format($top * $step) }}</text>
        @endforeach

        @foreach ($labels as $i => $label)
            @php($x0 = $left + $i * $groupW + ($groupW - count($series) * ($barW + 2)) / 2)
            @foreach ($series as $s => $one)
                @php($value = $one['values'][$i] ?? 0)
                @php($top_ = $y(max(0, $value)))
                @php($h = max(0, 8 + $plotH - $top_))
                <g class="ad-chart__bar">
                    <title>{{ $label }} · {{ $one['name'] }}: {{ $format($value) }}</title>
                    {{-- Generous hit area, then the visible bar. --}}
                    <rect x="{{ $x0 + $s * ($barW + 2) - 1 }}" y="8" width="{{ $barW + 2 }}" height="{{ $plotH }}" fill="transparent" />
                    @if ($h > 0)
                        <path fill="{{ $colours[$s] ?? $colours[0] }}"
                              d="M{{ $x0 + $s * ($barW + 2) }},{{ 8 + $plotH }} v{{ -max(0, $h - 4) }} q0,-4 4,-4 h{{ max(0, $barW - 8) }} q4,0 4,4 v{{ max(0, $h - 4) }} z" />
                    @endif
                </g>
            @endforeach
            @if ($i % $every === 0)
                <text x="{{ $left + $i * $groupW + $groupW / 2 }}" y="{{ $height - 6 }}" text-anchor="middle" class="ad-chart__tick">{{ $label }}</text>
            @endif
        @endforeach
        <line x1="{{ $left }}" x2="{{ $width }}" y1="{{ 8 + $plotH }}" y2="{{ 8 + $plotH }}" class="ad-chart__base" />
    </svg>

    @if (count($series) > 1)
        <figcaption class="ad-chart__legend">
            @foreach ($series as $s => $one)
                <span><i style="background:{{ $colours[$s] ?? $colours[0] }}"></i>{{ $one['name'] }}</span>
            @endforeach
        </figcaption>
    @endif

    <details class="ad-details">
        <summary class="ad-btn ad-btn--xs">{{ __('admin/stats.as_table') }}</summary>
        <table class="ad-table ad-table--dense">
            <thead><tr><th></th>@foreach ($series as $one)<th class="ad-end">{{ $one['name'] }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($labels as $i => $label)
                <tr><td>{{ $label }}</td>@foreach ($series as $one)<td class="ad-end ad-num">{{ $format($one['values'][$i] ?? 0) }}</td>@endforeach</tr>
            @endforeach
            </tbody>
        </table>
    </details>
</figure>
