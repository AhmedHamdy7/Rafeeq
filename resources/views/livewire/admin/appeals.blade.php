{{-- APPEALS (P). Everything waiting for a decision, oldest first; each row opens the page that decides it. --}}
<div>
    <x-admin.page-head :title="__('admin/appeals.title')" :sub="__('admin/appeals.sub')" />

    <div class="ad-tabs" role="tablist">
        <button type="button" role="tab" class="ad-tab" aria-selected="{{ $kind === 'all' ? 'true' : 'false' }}" wire:click="pick('all')">
            {{ __('admin/appeals.kinds.all') }} <span class="ad-badge">{{ $counts->sum() }}</span>
        </button>
        @foreach ($kinds as $name)
            <button type="button" role="tab" class="ad-tab" aria-selected="{{ $kind === $name ? 'true' : 'false' }}" wire:click="pick('{{ $name }}')">
                {{ __('admin/appeals.kinds.'.$name) }} <span class="ad-badge">{{ $counts->get($name, 0) }}</span>
            </button>
        @endforeach
    </div>

    <x-admin.card flush>
        @if ($rows->isEmpty())
            <x-admin.state kind="empty" :title="__('admin/appeals.empty')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin/appeals.kind') }}</th>
                        <th>{{ __('admin/appeals.who') }}</th>
                        <th>{{ __('admin/appeals.why') }}</th>
                        <th>{{ __('admin/appeals.waiting') }}</th>
                        <th><span class="ad-sr">{{ __('admin/appeals.open') }}</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        @php($late = $row['since']->lt(now()->subDay()))
                        <tr wire:key="appeal-{{ $row['kind'] }}-{{ $row['id'] }}" @class(['ad-row--flagged' => $late])>
                            <td data-label="{{ __('admin/appeals.kind') }}"><span class="ad-chip ad-chip--plain">{{ __('admin/appeals.kinds.'.$row['kind']) }}</span></td>
                            <td data-label="{{ __('admin/appeals.who') }}"><strong><bdi>{{ $row['who'] ?? '—' }}</bdi></strong></td>
                            <td data-label="{{ __('admin/appeals.why') }}" dir="auto" style="max-inline-size:420px">{{ \Illuminate\Support\Str::limit($row['text'], 140) }}</td>
                            <td data-label="{{ __('admin/appeals.waiting') }}">
                                <span class="ad-chip {{ $late ? 'ad-chip--bad' : 'ad-chip--ok' }}">{{ $row['since']->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 2) }}</span>
                            </td>
                            <td class="ad-end"><a class="ad-btn ad-btn--sm" href="{{ $row['url'] }}" wire:navigate>{{ __('admin/appeals.open') }}<x-admin.icon name="chevron" size="12" class="ad-flip" /></a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>
</div>
