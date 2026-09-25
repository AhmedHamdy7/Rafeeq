<div>
    <h1 style="margin:0 0 4px;font-size:19px">{{ __('admin.queue.title') }}</h1>
    <p style="margin:0 0 20px;font-size:13.5px;color:#6b6459">
        {{ trans_choice('admin.queue.waiting', $cards->count(), ['count' => $cards->count()]) }}
    </p>

    @error('queue') <p style="margin:0 0 14px;color:#b4232a;font-size:13px">{{ $message }}</p> @enderror

    @forelse ($cards as $card)
        <article style="background:#fff;border:1px solid #e8e4dc;border-radius:14px;padding:16px;margin-bottom:12px">
            <div style="display:flex;align-items:center;gap:12px">
                <span style="flex:none;width:38px;height:38px;border-radius:50%;background:#efecff;color:#4c3d8f;display:grid;place-items:center;font-weight:700;font-size:13px">{{ $card['initials'] }}</span>
                <div style="flex:1;min-width:0">
                    <div style="font-size:14.5px;font-weight:600">{{ $card['name'] }}</div>
                    <div style="font-size:12.5px;color:#6b6459">
                        {{ $card['role'] }} &middot; {{ $card['submittedAt']?->diffForHumans() ?? '—' }}
                    </div>
                </div>
                {{-- The risk pill directs attention. It never decides — see VerificationQueue. --}}
                @php($tone = ['ok' => ['#e7f5ee', '#1d6b4a'], 'warn' => ['#fdf1e3', '#8a5a1a'], 'bad' => ['#fdeaea', '#b4232a']][$card['risk']])
                <span style="flex:none;padding:4px 11px;border-radius:999px;font-size:11.5px;font-weight:700;background:{{ $tone[0] }};color:{{ $tone[1] }}">
                    {{ __('admin.queue.risk_' . $card['risk']) }}
                </span>
            </div>

            <ul style="margin:13px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:5px">
                @foreach ($card['checks'] as $check)
                    @php($mark = ['ok' => ['&check;', '#1d6b4a'], 'warn' => ['!', '#8a5a1a'], 'bad' => ['&times;', '#b4232a']][$check['mark']])
                    <li style="display:flex;gap:9px;font-size:13px">
                        <span style="flex:none;color:{{ $mark[1] }};font-weight:700">{!! $mark[0] !!}</span>
                        <span style="flex:1;color:#6b6459">{{ $check['label'] }}</span>
                        <span>{{ $check['value'] }}</span>
                    </li>
                @endforeach
            </ul>

            @if ($mayDecide)
                <div style="display:flex;gap:9px;margin-top:15px;flex-wrap:wrap">
                    <button type="button" wire:click="approve('{{ $card['id'] }}')" wire:loading.attr="disabled"
                            style="padding:9px 16px;border:none;border-radius:999px;background:#1d6b4a;color:#fff;font-size:13px;font-weight:600;cursor:pointer">
                        {{ __('admin.queue.approve') }}
                    </button>
                    <button type="button" wire:click="$set('openId', '{{ $card['id'] }}')"
                            style="padding:9px 16px;border:1px solid #ddd8ce;border-radius:999px;background:#faf9f5;font-size:13px;font-weight:600;cursor:pointer">
                        {{ __('admin.queue.ask_info') }}
                    </button>
                </div>

                @if ($openId === $card['id'])
                    <form wire:submit="requestInfo('{{ $card['id'] }}')" style="margin-top:13px">
                        {{-- 🔒 Shown to the person verbatim, so the placeholder asks for something actionable. --}}
                        <textarea wire:model="reason" rows="3" required
                                  placeholder="{{ __('admin.queue.reason_placeholder') }}"
                                  style="width:100%;padding:10px 12px;border:1px solid #ddd8ce;border-radius:9px;font-size:13.5px;font-family:inherit"></textarea>
                        @error('reason') <p style="margin:6px 0 0;color:#b4232a;font-size:12.5px">{{ $message }}</p> @enderror
                        <button type="submit" style="margin-top:9px;padding:9px 16px;border:none;border-radius:999px;background:#4c3d8f;color:#fff;font-size:13px;font-weight:600;cursor:pointer">
                            {{ __('admin.queue.send_request') }}
                        </button>
                    </form>
                @endif
            @else
                {{-- Support agents land here: they can see the queue is moving, nothing more. --}}
                <p style="margin:14px 0 0;font-size:12.5px;color:#8b857a">{{ __('admin.queue.view_only') }}</p>
            @endif
        </article>
    @empty
        <p style="padding:26px;text-align:center;color:#8b857a;font-size:13.5px;background:#fff;border:1px solid #e8e4dc;border-radius:14px">
            {{ __('admin.queue.empty') }}
        </p>
    @endforelse
</div>
