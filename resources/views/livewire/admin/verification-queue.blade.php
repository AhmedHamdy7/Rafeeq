<div>
    <h1 class="rq-h1">{{ __('admin.queue.title') }}</h1>
    {{--
        `total()`, not `count()`. On a paginator `count()` is the size of the page being
        looked at, so the heading would have announced "10 people waiting" to a reviewer
        with 240 in the queue.
    --}}
    <p class="rq-sub">{{ trans_choice('admin.queue.waiting', $cards->total(), ['count' => $cards->total()]) }}</p>

    @error('queue') <p class="rq-error" style="margin-bottom:14px">{{ $message }}</p> @enderror

    @forelse ($cards as $card)
        {{--
            `wire:key` so Livewire re-uses the right card when the list shifts. Without it,
            deciding the top card can leave the expanded reason box attached to whichever
            card slid into its place — and the reviewer sends the wrong person a message.
        --}}
        <article class="rq-card" wire:key="verification-{{ $card['id'] }}">
            <div style="display:flex;align-items:center;gap:12px">
                <span class="rq-avatar">{{ $card['initials'] }}</span>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:600">{{ $card['name'] }}</div>
                    <div class="rq-note">
                        {{ $card['role'] }} &middot; {{ $card['submittedAt']?->diffForHumans() ?? '—' }}
                    </div>
                </div>
                {{-- The risk pill directs attention. It never decides — see VerificationQueue. --}}
                <span class="rq-pill rq-pill--{{ $card['risk'] }}">
                    {{ __('admin.queue.risk_' . $card['risk']) }}
                </span>
            </div>

            <ul class="rq-checks">
                @foreach ($card['checks'] as $check)
                    <li class="rq-check">
                        <span class="rq-check__mark rq-check__mark--{{ $check['mark'] }}" aria-hidden="true">
                            {{ ['ok' => '✓', 'warn' => '!', 'bad' => '✕'][$check['mark']] }}
                        </span>
                        <span class="rq-check__label">{{ $check['label'] }}</span>
                        <span>{{ $check['value'] }}</span>
                    </li>
                @endforeach
            </ul>

            @if ($maySeeDocuments && $card['documents'] !== [])
                {{-- Opened in a new tab through the session; every opening is audited. --}}
                <div class="rq-actions" style="margin-top:8px">
                    @foreach ($card['documents'] as $document)
                        <a class="rq-btn rq-btn--quiet" target="_blank" rel="noopener"
                           href="{{ route('admin.files.document', $document['id']) }}">{{ __('admin.files.open', ['what' => $document['label']]) }}</a>
                    @endforeach
                </div>
            @endif

            @if ($mayDecide)
                <div class="rq-actions">
                    <button type="button" class="rq-btn rq-btn--approve"
                            wire:click="approve('{{ $card['id'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="approve('{{ $card['id'] }}')">
                        <span wire:loading.remove wire:target="approve('{{ $card['id'] }}')">{{ __('admin.queue.approve') }}</span>
                        <span wire:loading wire:target="approve('{{ $card['id'] }}')">{{ __('admin.queue.working') }}</span>
                    </button>
                    <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openId', '{{ $card['id'] }}')">
                        {{ __('admin.queue.ask_info') }}
                    </button>
                </div>

                @if ($openId === $card['id'])
                    <form wire:submit="requestInfo('{{ $card['id'] }}')" style="margin-top:13px">
                        {{-- 🔒 Shown to the person verbatim, so the placeholder asks for something actionable. --}}
                        <textarea class="rq-field" wire:model="reason" rows="3" required
                                  placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                        @error('reason') <p class="rq-error">{{ $message }}</p> @enderror
                        <div class="rq-actions">
                            <button type="submit" class="rq-btn rq-btn--primary" wire:loading.attr="disabled">
                                {{ __('admin.queue.send_request') }}
                            </button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('openId', null)">
                                {{ __('admin.queue.cancel') }}
                            </button>
                        </div>
                    </form>
                @endif
            @else
                {{-- Support agents land here: they can see the queue is moving, nothing more. --}}
                <p class="rq-note" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
            @endif
        </article>
    @empty
        <p class="rq-empty">{{ __('admin.queue.empty') }}</p>
    @endforelse

    <x-rq-pager :page="$cards" />
</div>
