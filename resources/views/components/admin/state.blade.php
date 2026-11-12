{{--
    The four page states every list has: empty, loading, error, locked (no access).
    <x-admin.state kind="empty" :title="…" :text="…" />
    Loading draws grey bars instead of text, so the page does not jump when data lands.
--}}
@props(['kind' => 'empty', 'title' => null, 'text' => null, 'rows' => 4])
@if ($kind === 'loading')
    <div {{ $attributes->merge(['class' => 'ad-stack', 'style' => 'padding:16px']) }} role="status" aria-live="polite">
        <span class="ad-sr">{{ __('admin/shell.states.loading') }}</span>
        @for ($i = 0; $i < $rows; $i++)
            <span class="ad-skel" style="inline-size:{{ [92, 76, 84, 60][$i % 4] }}%"></span>
        @endfor
    </div>
@else
    @php
        $icon = ['empty' => 'inbox', 'error' => 'alert', 'locked' => 'lock'][$kind] ?? 'info';
        $title ??= __('admin/shell.states.'.($kind === 'locked' ? 'locked' : $kind).'_title');
        $text ??= match ($kind) {
            'error' => __('admin/shell.states.error_text'),
            'locked' => __('admin/shell.states.locked_text'),
            default => null,
        };
    @endphp
    <div {{ $attributes->class(['ad-state', 'ad-state--'.$kind]) }} @if ($kind === 'error') role="alert" @endif>
        <span class="ad-state__icon"><x-admin.icon :name="$icon" size="20" /></span>
        <p class="ad-state__title">{{ $title }}</p>
        @if ($text)
            <p class="ad-state__text">{{ $text }}</p>
        @endif
        {{ $slot }}
    </div>
@endif
