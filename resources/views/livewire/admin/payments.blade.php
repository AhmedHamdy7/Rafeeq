{{--
    PAYMENTS. The InstaPay transfers members say they sent, oldest first: confirm when the money is
    in the statement, refuse with a reason when it is not. Below, a coupon for the harmed side of a
    collected fee, and a credit by hand.
--}}
<div>
    <h1 class="rq-h1">{{ __('admin.payments.title') }}</h1>
    <p class="rq-sub">{{ trans_choice('admin.payments.pending_count', $pending->total(), ['count' => $pending->total()]) }}</p>

    @if (session('status'))
        <p class="rq-note" role="status" style="margin-bottom:12px">{{ session('status') }}</p>
    @endif

    @forelse ($pending as $payment)
        <article class="rq-card" wire:key="payment-{{ $payment->id }}">
            <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                <div style="flex:1;min-width:200px">
                    <div style="font-weight:600">
                        <bdi>{{ $payment->user?->public_first_name ?? '—' }}</bdi>
                        &middot; {{ __('admin.payments.side_'.$payment->side->value) }}
                    </div>
                    <div class="rq-note">
                        {{ __('admin.payments.sent', ['amount' => number_format($payment->amount_piastres / 100, 2)]) }}
                        &middot; {{ __('admin.payments.reference') }} <bdi>{{ $payment->instapay_reference }}</bdi>
                        &middot; {{ __('admin.payments.owes', ['amount' => number_format(($owed[$payment->id] ?? 0) / 100, 2)]) }}
                        &middot; {{ $payment->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }}
                    </div>
                </div>
                @if ($payment->receipt_path !== null)
                    <span class="rq-pill rq-pill--count">{{ __('admin.payments.has_receipt') }}</span>
                @endif
            </div>

            @if ($mayAct)
                @if ($refusing === $payment->id)
                    <form wire:submit="refuse('{{ $payment->id }}')" style="margin-top:13px">
                        <textarea class="rq-field" wire:model="note" rows="2" required
                                  placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                        @error('note') <p class="rq-error">{{ $message }}</p> @enderror
                        <div class="rq-actions">
                            <button type="submit" class="rq-btn rq-btn--danger" wire:loading.attr="disabled">{{ __('admin.payments.refuse') }}</button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('refusing', null)">{{ __('admin.queue.cancel') }}</button>
                        </div>
                    </form>
                @else
                    <div class="rq-actions">
                        <button type="button" class="rq-btn rq-btn--primary" wire:click="confirm('{{ $payment->id }}')" wire:loading.attr="disabled">{{ __('admin.payments.confirm') }}</button>
                        <button type="button" class="rq-btn rq-btn--quiet" wire:click="openRefuse('{{ $payment->id }}')">{{ __('admin.payments.refuse') }}</button>
                    </div>
                @endif
            @endif
        </article>
    @empty
        <p class="rq-note">{{ __('admin.payments.none_pending') }}</p>
    @endforelse

    <x-rq-pager :page="$pending" />

    <h2 class="rq-h1" style="font-size:18px;margin-top:24px">{{ __('admin.payments.appeals_title') }}</h2>
    @forelse ($appeals as $appeal)
        <article class="rq-card" wire:key="appeal-{{ $appeal->id }}">
            <div style="font-weight:600">
                <bdi>{{ $appeal->user?->public_first_name ?? '—' }}</bdi>
                &middot; {{ __('admin.payments.kind_'.$appeal->kind->value) }}
                &middot; {{ $appeal->occurred_on->toDateString() }}
            </div>
            <div class="rq-note">
                {{ __('admin.payments.appeal_fee', ['amount' => number_format($appeal->fee_piastres / 100, 2), 'status' => $appeal->fee_status->value]) }}
                &middot; <bdi>{{ $appeal->dispute_reason }}</bdi>
            </div>
            @if ($mayAct)
                @if ($deciding === $appeal->id)
                    <div style="margin-top:13px">
                        <textarea class="rq-field" wire:model="appealNote" rows="2" required
                                  placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                        @error('appealNote') <p class="rq-error">{{ $message }}</p> @enderror
                        <div class="rq-actions">
                            <button type="button" class="rq-btn rq-btn--primary" wire:click="decideAppeal('{{ $appeal->id }}', true)">{{ __('admin.payments.overturn') }}</button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="decideAppeal('{{ $appeal->id }}', false)">{{ __('admin.payments.uphold') }}</button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('deciding', null)">{{ __('admin.queue.cancel') }}</button>
                        </div>
                    </div>
                @else
                    <div class="rq-actions">
                        <button type="button" class="rq-btn rq-btn--quiet" wire:click="openAppeal('{{ $appeal->id }}')">{{ __('admin.payments.decide') }}</button>
                    </div>
                @endif
            @endif
        </article>
    @empty
        <p class="rq-note">{{ __('admin.payments.no_appeals') }}</p>
    @endforelse

    @foreach ($driverAppeals as $appeal)
        <article class="rq-card" wire:key="driver-appeal-{{ $appeal->id }}">
            <div style="font-weight:600">
                <bdi>{{ $appeal->user?->public_first_name ?? '—' }}</bdi>
                &middot; {{ __('admin.payments.side_driver') }}
                &middot; <bdi>{{ $appeal->debtEntry?->reason_code }}</bdi>
            </div>
            <div class="rq-note">
                {{ __('admin.payments.appeal_fee', ['amount' => number_format(($appeal->debtEntry?->amount_piastres ?? 0) / 100, 2), 'status' => 'charged']) }}
                &middot; <bdi>{{ $appeal->reason }}</bdi>
            </div>
            @if ($mayAct)
                @if ($deciding === $appeal->id)
                    <div style="margin-top:13px">
                        <textarea class="rq-field" wire:model="appealNote" rows="2" required
                                  placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                        @error('appealNote') <p class="rq-error">{{ $message }}</p> @enderror
                        <div class="rq-actions">
                            <button type="button" class="rq-btn rq-btn--primary" wire:click="decideDriverAppeal('{{ $appeal->id }}', true)">{{ __('admin.payments.overturn') }}</button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="decideDriverAppeal('{{ $appeal->id }}', false)">{{ __('admin.payments.uphold') }}</button>
                            <button type="button" class="rq-btn rq-btn--quiet" wire:click="$set('deciding', null)">{{ __('admin.queue.cancel') }}</button>
                        </div>
                    </div>
                @else
                    <div class="rq-actions">
                        <button type="button" class="rq-btn rq-btn--quiet" wire:click="openAppeal('{{ $appeal->id }}')">{{ __('admin.payments.decide') }}</button>
                    </div>
                @endif
            @endif
        </article>
    @endforeach

    @if ($mayAct)
        <h2 class="rq-h1" style="font-size:18px;margin-top:24px">{{ __('admin.payments.coupon_title') }}</h2>
        <p class="rq-note">{{ __('admin.payments.coupon_help') }}</p>
        <form wire:submit="issueCoupon" class="rq-card">
            <label class="rq-label" for="coupon-fee">{{ __('admin.payments.fee_line') }}</label>
            <input id="coupon-fee" class="rq-field" wire:model="couponFeeLine" required>
            @error('couponFeeLine') <p class="rq-error">{{ $message }}</p> @enderror
            <textarea class="rq-field" wire:model="couponNote" rows="2" required style="margin-top:10px"
                      placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
            @error('couponNote') <p class="rq-error">{{ $message }}</p> @enderror
            <div class="rq-actions">
                <button type="submit" class="rq-btn rq-btn--primary" wire:loading.attr="disabled">{{ __('admin.payments.issue_coupon') }}</button>
            </div>
        </form>

        <h2 class="rq-h1" style="font-size:18px;margin-top:24px">{{ __('admin.payments.credit_title') }}</h2>
        <form wire:submit="credit" class="rq-card">
            <label class="rq-label" for="credit-user">{{ __('admin.payments.member') }}</label>
            <input id="credit-user" class="rq-field" wire:model="creditUser" required>
            @error('creditUser') <p class="rq-error">{{ $message }}</p> @enderror

            <label class="rq-label" for="credit-side" style="margin-top:10px">{{ __('admin.payments.side') }}</label>
            <select id="credit-side" class="rq-field" wire:model="creditSide">
                <option value="driver">{{ __('admin.payments.side_driver') }}</option>
                <option value="passenger">{{ __('admin.payments.side_passenger') }}</option>
            </select>

            <label class="rq-label" for="credit-amount" style="margin-top:10px">{{ __('admin.payments.amount') }}</label>
            <input id="credit-amount" type="number" min="1" class="rq-field" wire:model="creditPiastres" required>
            @error('creditPiastres') <p class="rq-error">{{ $message }}</p> @enderror

            <textarea class="rq-field" wire:model="creditNote" rows="2" required style="margin-top:10px"
                      placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
            @error('creditNote') <p class="rq-error">{{ $message }}</p> @enderror
            <div class="rq-actions">
                <button type="submit" class="rq-btn rq-btn--primary" wire:loading.attr="disabled">{{ __('admin.payments.credit') }}</button>
            </div>
        </form>
    @endif
</div>
