{{-- Figure tiles with their change against the previous period. Needs $tiles from the Statistics view. --}}
<div class="ad-grid ad-grid--4">
    @foreach ($tiles as $one)
        <x-admin.card flush>
            <div class="ad-stat">
                <span class="ad-stat__label">{{ $one['label'] }}</span>
                <span class="ad-stat__row">
                    <span class="ad-stat__value ad-num" style="font-size:20px">{{ $one['value'] }}</span>
                    @if ($one['change'] !== null)
                        @php($good = ($one['change']['up'] xor $one['lowerIsBetter']))
                        <span class="ad-trend {{ $one['change']['pct'] == 0 ? '' : ($good ? 'ad-trend--up' : 'ad-trend--down') }}">
                            {{ $one['change']['up'] ? '▲' : '▼' }} {{ abs($one['change']['pct']) }}%
                        </span>
                    @endif
                </span>
                @if ($one['foot'])
                    <span class="ad-stat__foot">{{ $one['foot'] }}</span>
                @endif
            </div>
        </x-admin.card>
    @endforeach
</div>
