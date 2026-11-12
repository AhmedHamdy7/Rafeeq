{{--
    PAYMENTS (I, K, P money appeals). The InstaPay transfers members say they sent, oldest
    first: confirm when the money is in the statement, refuse with a reason when it is not.
    Then the money appeals, then a coupon for the harmed side of a collected fee, and a
    credit by hand. Amounts are EGP with two decimals; digits stay Western in Arabic.
--}}
<div>
    <x-admin.page-head :title="__('admin.payments.title')" :sub="trans_choice('admin.payments.pending_count', $pending->total(), ['count' => $pending->total()])" />

    @if (session('status'))
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ session('status') }}</p>
    @endif

    {{-- ─────────── Transfers to confirm (I) ─────────── --}}
    <x-admin.card flush>
        @if ($pending->isEmpty())
            <x-admin.state kind="empty" :title="__('admin.payments.none_pending')" />
        @else
            <div class="ad-table-wrap">
                <table class="ad-table ad-table--cards">
                    <thead>
                    <tr>
                        <th>{{ __('admin.payments.member') }}</th>
                        <th class="ad-end">{{ __('admin.payments.amount') }}</th>
                        <th>{{ __('admin.payments.reference') }}</th>
                        <th class="ad-end">{{ __('admin/payments.owes') }}</th>
                        <th>{{ __('admin/payments.sent_at') }}</th>
                        <th><span class="ad-sr">{{ __('admin/payments.actions') }}</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($pending as $payment)
                        <tr wire:key="payment-{{ $payment->id }}">
                            <td data-label="{{ __('admin.payments.member') }}">
                                <strong><bdi>{{ $payment->user?->public_first_name ?? '—' }}</bdi></strong>
                                <span class="ad-chip ad-chip--plain">{{ __('admin.payments.side_'.$payment->side->value) }}</span>
                                @if ($payment->receipt_path !== null)
                                    <span class="ad-chip ad-chip--info ad-chip--plain">{{ __('admin.payments.has_receipt') }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('admin.payments.amount') }}" class="ad-end ad-num" style="font-weight:700">
                                {{ __('admin.payments.sent', ['amount' => number_format($payment->amount_piastres / 100, 2)]) }}
                            </td>
                            <td data-label="{{ __('admin.payments.reference') }}"><span class="ad-mono"><bdi>{{ $payment->instapay_reference }}</bdi></span></td>
                            <td data-label="{{ __('admin/payments.owes') }}" class="ad-end ad-num ad-muted">
                                {{ __('admin.payments.owes', ['amount' => number_format(($owed[$payment->id] ?? 0) / 100, 2)]) }}
                            </td>
                            <td data-label="{{ __('admin/payments.sent_at') }}" class="ad-muted ad-num">{{ $payment->created_at->timezone('Africa/Cairo')->format('Y-m-d H:i') }}</td>
                            <td class="ad-end">
                                @if ($mayAct && $refusing !== $payment->id)
                                    <div class="ad-row" style="justify-content:flex-end;gap:6px;flex-wrap:nowrap">
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--primary" wire:click="confirm('{{ $payment->id }}')" wire:loading.attr="disabled">{{ __('admin.payments.confirm') }}</button>
                                        <button type="button" class="ad-btn ad-btn--sm ad-btn--dangerline" wire:click="openRefuse('{{ $payment->id }}')">{{ __('admin.payments.refuse') }}</button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                        @if ($mayAct && $refusing === $payment->id)
                            <tr class="ad-row-detail" wire:key="payment-refuse-{{ $payment->id }}">
                                <td colspan="6">
                                    <form wire:submit="refuse('{{ $payment->id }}')">
                                        <div class="ad-field">
                                            <textarea class="ad-textarea" wire:model="note" rows="2" required
                                                      aria-label="{{ __('admin.payments.note_placeholder') }}"
                                                      placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                                            @error('note') <span class="ad-error">{{ $message }}</span> @enderror
                                        </div>
                                        <div class="ad-row">
                                            <button type="submit" class="ad-btn ad-btn--danger" wire:loading.attr="disabled">{{ __('admin.payments.refuse') }}</button>
                                            <button type="button" class="ad-btn" wire:click="$set('refusing', null)">{{ __('admin.queue.cancel') }}</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-admin.card>

    <x-rq-pager :page="$pending" />

    {{-- ─────────── Money appeals ─────────── --}}
    <div class="ad-section-label">{{ __('admin.payments.appeals_title') }}</div>
    <div class="ad-stack">
        @if ($appeals->isEmpty() && $driverAppeals->isEmpty())
            <x-admin.card flush><x-admin.state kind="empty" :title="__('admin.payments.no_appeals')" /></x-admin.card>
        @endif

        @foreach ($appeals as $appeal)
            <x-admin.card flush wire:key="appeal-{{ $appeal->id }}">
                <div class="ad-card__body">
                    <div style="font-weight:700">
                        <bdi>{{ $appeal->user?->public_first_name ?? '—' }}</bdi>
                        · {{ __('admin.payments.kind_'.$appeal->kind->value) }}
                        · <span class="ad-num">{{ $appeal->occurred_on->toDateString() }}</span>
                    </div>
                    <div class="ad-muted" style="font-size:12.5px">{{ __('admin.payments.appeal_fee', ['amount' => number_format($appeal->fee_piastres / 100, 2), 'status' => $appeal->fee_status->value]) }}</div>
                    <p style="margin:8px 0 0;white-space:pre-line" dir="auto">{{ $appeal->dispute_reason }}</p>
                    @if ($mayAct)
                        @if ($deciding === $appeal->id)
                            <div class="ad-confirm">
                                <div class="ad-field">
                                    <textarea class="ad-textarea" wire:model="appealNote" rows="2" required
                                              aria-label="{{ __('admin.payments.note_placeholder') }}"
                                              placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                                    @error('appealNote') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="button" class="ad-btn ad-btn--primary" wire:click="decideAppeal('{{ $appeal->id }}', true)">{{ __('admin.payments.overturn') }}</button>
                                    <button type="button" class="ad-btn" wire:click="decideAppeal('{{ $appeal->id }}', false)">{{ __('admin.payments.uphold') }}</button>
                                    <button type="button" class="ad-btn ad-btn--quiet" wire:click="$set('deciding', null)">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </div>
                        @else
                            <div class="ad-row" style="margin-block-start:12px">
                                <button type="button" class="ad-btn ad-btn--sm" wire:click="openAppeal('{{ $appeal->id }}')">{{ __('admin.payments.decide') }}</button>
                            </div>
                        @endif
                    @endif
                </div>
            </x-admin.card>
        @endforeach

        @foreach ($driverAppeals as $appeal)
            <x-admin.card flush wire:key="driver-appeal-{{ $appeal->id }}">
                <div class="ad-card__body">
                    <div style="font-weight:700">
                        <bdi>{{ $appeal->user?->public_first_name ?? '—' }}</bdi>
                        · {{ __('admin.payments.side_driver') }}
                        · <span class="ad-code"><bdi>{{ $appeal->debtEntry?->reason_code }}</bdi></span>
                    </div>
                    <div class="ad-muted" style="font-size:12.5px">{{ __('admin.payments.appeal_fee', ['amount' => number_format(($appeal->debtEntry?->amount_piastres ?? 0) / 100, 2), 'status' => 'charged']) }}</div>
                    <p style="margin:8px 0 0;white-space:pre-line" dir="auto">{{ $appeal->reason }}</p>
                    @if ($mayAct)
                        @if ($deciding === $appeal->id)
                            <div class="ad-confirm">
                                <div class="ad-field">
                                    <textarea class="ad-textarea" wire:model="appealNote" rows="2" required
                                              aria-label="{{ __('admin.payments.note_placeholder') }}"
                                              placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                                    @error('appealNote') <span class="ad-error">{{ $message }}</span> @enderror
                                </div>
                                <div class="ad-row">
                                    <button type="button" class="ad-btn ad-btn--primary" wire:click="decideDriverAppeal('{{ $appeal->id }}', true)">{{ __('admin.payments.overturn') }}</button>
                                    <button type="button" class="ad-btn" wire:click="decideDriverAppeal('{{ $appeal->id }}', false)">{{ __('admin.payments.uphold') }}</button>
                                    <button type="button" class="ad-btn ad-btn--quiet" wire:click="$set('deciding', null)">{{ __('admin.queue.cancel') }}</button>
                                </div>
                            </div>
                        @else
                            <div class="ad-row" style="margin-block-start:12px">
                                <button type="button" class="ad-btn ad-btn--sm" wire:click="openAppeal('{{ $appeal->id }}')">{{ __('admin.payments.decide') }}</button>
                            </div>
                        @endif
                    @endif
                </div>
            </x-admin.card>
        @endforeach
    </div>

    @if ($mayAct)
        <div class="ad-grid ad-grid--2" style="align-items:start;margin-block-start:20px">
            {{-- ─────────── Coupon (K) ─────────── --}}
            <x-admin.card :title="__('admin.payments.coupon_title')" bar>
                <p class="ad-hint" style="margin:0 0 12px">{{ __('admin.payments.coupon_help') }}</p>
                <form wire:submit="issueCoupon">
                    <div class="ad-field">
                        <label class="ad-label" for="coupon-fee">{{ __('admin.payments.fee_line') }}</label>
                        <input id="coupon-fee" class="ad-input ad-mono" dir="ltr" wire:model="couponFeeLine" required>
                        @error('couponFeeLine') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="ad-field">
                        <textarea class="ad-textarea" wire:model="couponNote" rows="2" required
                                  aria-label="{{ __('admin.payments.note_placeholder') }}"
                                  placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                        @error('couponNote') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin.payments.issue_coupon') }}</button>
                </form>
            </x-admin.card>

            {{-- ─────────── Credit by hand ─────────── --}}
            <x-admin.card :title="__('admin.payments.credit_title')" bar>
                <form wire:submit="credit">
                    <div class="ad-field">
                        <label class="ad-label" for="credit-user">{{ __('admin.payments.member') }}</label>
                        <input id="credit-user" class="ad-input ad-mono" dir="ltr" wire:model="creditUser" required>
                        @error('creditUser') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <div class="ad-fields-inline">
                        <div class="ad-field">
                            <label class="ad-label" for="credit-side">{{ __('admin.payments.side') }}</label>
                            <select id="credit-side" class="ad-select" wire:model="creditSide">
                                <option value="driver">{{ __('admin.payments.side_driver') }}</option>
                                <option value="passenger">{{ __('admin.payments.side_passenger') }}</option>
                            </select>
                        </div>
                        <div class="ad-field">
                            <label class="ad-label" for="credit-amount">{{ __('admin.payments.amount') }}</label>
                            <input id="credit-amount" type="number" min="1" class="ad-input" wire:model="creditPiastres" required>
                            @error('creditPiastres') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="ad-field">
                        <textarea class="ad-textarea" wire:model="creditNote" rows="2" required
                                  aria-label="{{ __('admin.payments.note_placeholder') }}"
                                  placeholder="{{ __('admin.payments.note_placeholder') }}"></textarea>
                        @error('creditNote') <span class="ad-error">{{ $message }}</span> @enderror
                    </div>
                    <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin.payments.credit') }}</button>
                </form>
            </x-admin.card>
        </div>
    @endif
</div>
