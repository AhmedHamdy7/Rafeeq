{{-- IDENTITY CHECKS (V). One card per person waiting, oldest first; the checks, the documents, the decision. --}}
<div>
    {{--
        `total()`, not `count()`. On a paginator `count()` is the size of the page being
        looked at, so the heading would have announced "10 people waiting" to a reviewer
        with 240 in the queue.
    --}}
    <x-admin.page-head :title="__('admin.queue.title')" :sub="trans_choice('admin.queue.waiting', $cards->total(), ['count' => $cards->total()])" />

    @error('queue') <p class="ad-flash ad-flash--error" role="alert"><x-admin.icon name="alert" />{{ $message }}</p> @enderror

    <div class="ad-grid ad-grid--2" style="align-items:start">
        @forelse ($cards as $card)
            {{--
                `wire:key` so Livewire re-uses the right card when the list shifts. Without it,
                deciding the top card can leave the expanded reason box attached to whichever
                card slid into its place — and the reviewer sends the wrong person a message.
            --}}
            <x-admin.card flush wire:key="verification-{{ $card['id'] }}">
                <div class="ad-card__body">
                    <div class="ad-row" style="gap:12px;flex-wrap:nowrap">
                        <span class="ad-avatar" aria-hidden="true">{{ $card['initials'] }}</span>
                        <div style="flex:1;min-inline-size:0">
                            <div style="font-weight:700"><bdi>{{ $card['name'] }}</bdi></div>
                            <div class="ad-muted" style="font-size:12.5px">{{ $card['role'] }} · {{ $card['submittedAt']?->diffForHumans() ?? '—' }}</div>
                        </div>
                        {{-- The risk chip directs attention. It never decides — see VerificationQueue. --}}
                        <span class="ad-chip ad-chip--{{ $card['risk'] }}">{{ __('admin.queue.risk_'.$card['risk']) }}</span>
                    </div>

                    <ul class="ad-checklist">
                        @foreach ($card['checks'] as $check)
                            <li>
                                <span class="ad-checklist__mark ad-checklist__mark--{{ $check['mark'] }}" aria-hidden="true">
                                    <x-admin.icon :name="['ok' => 'check', 'warn' => 'info', 'bad' => 'x'][$check['mark']]" size="13" />
                                </span>
                                <span class="ad-checklist__label">{{ $check['label'] }}</span>
                                <span>{{ $check['value'] }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($maySeeDocuments && $card['documents'] !== [])
                        {{-- Opened in a new tab through the session; every opening is audited. --}}
                        <div class="ad-row" style="margin-block-start:12px">
                            @foreach ($card['documents'] as $document)
                                <a class="ad-btn ad-btn--sm" target="_blank" rel="noopener"
                                   href="{{ route('admin.files.document', $document['id']) }}"><x-admin.icon name="file" size="14" />{{ __('admin.files.open', ['what' => $document['label']]) }}</a>
                            @endforeach
                        </div>
                    @endif

                    @if ($mayDecide)
                        <div class="ad-row" style="margin-block-start:14px">
                            <button type="button" class="ad-btn ad-btn--primary"
                                    wire:click="approve('{{ $card['id'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="approve('{{ $card['id'] }}')">
                                <span wire:loading.remove wire:target="approve('{{ $card['id'] }}')">{{ __('admin.queue.approve') }}</span>
                                <span wire:loading wire:target="approve('{{ $card['id'] }}')">{{ __('admin.queue.working') }}</span>
                            </button>
                            <button type="button" class="ad-btn" wire:click="$set('openId', '{{ $card['id'] }}')">{{ __('admin.queue.ask_info') }}</button>
                        </div>

                        @if ($openId === $card['id'])
                            <form wire:submit="requestInfo('{{ $card['id'] }}')" class="ad-confirm">
                                {{-- 🔒 Shown to the person verbatim, so the placeholder asks for something actionable. --}}
                                <div class="ad-field">
                                    <textarea class="ad-textarea" wire:model="reason" rows="3" required
                                              aria-label="{{ __('admin.queue.reason_placeholder') }}"
                                              placeholder="{{ __('admin.queue.reason_placeholder') }}"></textarea>
                                    @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin.queue.send_request') }}</button>
                                    <button type="button" class="ad-btn" wire:click="$set('openId', null)">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </form>
                        @endif
                    @else
                        {{-- Support agents land here: they can see the queue is moving, nothing more. --}}
                        <p class="ad-hint" style="margin:14px 0 0">{{ __('admin.queue.view_only') }}</p>
                    @endif
                </div>
            </x-admin.card>
        @empty
            <x-admin.card flush style="grid-column:1/-1">
                <x-admin.state kind="empty" :title="__('admin.queue.empty')" />
            </x-admin.card>
        @endforelse
    </div>

    <x-rq-pager :page="$cards" />
</div>
