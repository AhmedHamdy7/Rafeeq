{{-- ANNOUNCEMENTS AND DELIVERY LOG (Z01, Z02, Z04). Inbox only, never a push (NOTIF-02). --}}
<div>
    <x-admin.page-head :title="__('admin/communication.title')" :sub="__('admin/communication.sub')" />

    @if (count($tabs) > 1)
        <div class="ad-tabs" role="tablist">
            @foreach ($tabs as $name)
                <button type="button" role="tab" class="ad-tab" aria-selected="{{ $tab === $name ? 'true' : 'false' }}" wire:click="pick('{{ $name }}')">{{ __('admin/communication.tabs.'.$name) }}</button>
            @endforeach
        </div>
    @endif

    @if ($done)
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ $done }}</p>
    @endif

    @if ($tab === 'compose' && $mayWrite)
        <div class="ad-split">
            <x-admin.card :title="__('admin/communication.new')" bar>
                <div class="ad-notice" style="margin-block-end:12px"><x-admin.icon name="info" /><span>{{ __('admin/communication.inbox_only') }}</span></div>
                <form wire:submit="send">
                    <div class="ad-field">
                        <label class="ad-label" for="an-audience">{{ __('admin/communication.audience') }}</label>
                        <select id="an-audience" class="ad-select" wire:model.live="audience">
                            @foreach ($audiences as $option)
                                <option value="{{ $option->value }}">{{ __('admin/communication.audiences.'.$option->value) }}</option>
                            @endforeach
                        </select>
                        <span class="ad-hint">{{ trans_choice('admin/communication.reach', $reach ?? 0, ['count' => number_format($reach ?? 0)]) }}</span>
                    </div>
                    <div class="ad-fields-inline">
                        <div class="ad-field">
                            <label class="ad-label" for="an-title-ar">{{ __('admin/communication.title_ar') }} *</label>
                            <input id="an-title-ar" class="ad-input" dir="rtl" lang="ar" wire:model.live.debounce.400ms="titleAr" maxlength="120" required>
                            @error('titleAr') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ad-field">
                            <label class="ad-label" for="an-title-en">{{ __('admin/communication.title_en') }} *</label>
                            <input id="an-title-en" class="ad-input" dir="ltr" lang="en" wire:model.live.debounce.400ms="titleEn" maxlength="120" required>
                            @error('titleEn') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="ad-fields-inline">
                        <div class="ad-field">
                            <label class="ad-label" for="an-body-ar">{{ __('admin/communication.body_ar') }} *</label>
                            <textarea id="an-body-ar" class="ad-textarea" rows="4" dir="rtl" lang="ar" wire:model.live.debounce.400ms="bodyAr" maxlength="1000" required></textarea>
                            @error('bodyAr') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ad-field">
                            <label class="ad-label" for="an-body-en">{{ __('admin/communication.body_en') }} *</label>
                            <textarea id="an-body-en" class="ad-textarea" rows="4" dir="ltr" lang="en" wire:model.live.debounce.400ms="bodyEn" maxlength="1000" required></textarea>
                            @error('bodyEn') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="ad-fields-inline">
                        <div class="ad-field">
                            <label class="ad-label" for="an-link">{{ __('admin/communication.link') }}</label>
                            <select id="an-link" class="ad-select" wire:model="link">
                                <option value="">{{ __('admin/communication.no_link') }}</option>
                                @foreach ($links as $option)
                                    <option value="{{ $option }}">{{ __('admin/communication.links.'.$option) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="ad-field">
                            <label class="ad-label" for="an-at">{{ __('admin/communication.schedule') }}</label>
                            <input id="an-at" type="datetime-local" class="ad-input" wire:model.live="scheduleAt">
                            <span class="ad-hint">{{ __('admin/communication.schedule_hint') }}</span>
                            @error('scheduleAt') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                    </div>
                    <div class="ad-row">
                        <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">
                            {{ $scheduleAt !== '' ? __('admin/communication.schedule_it') : __('admin/communication.send_now') }}
                        </button>
                    </div>
                </form>
            </x-admin.card>

            <x-admin.card :title="__('admin/communication.preview')" bar>
                @foreach (['ar' => [$titleAr, $bodyAr], 'en' => [$titleEn, $bodyEn]] as $lang => [$previewTitle, $previewBody])
                    <div class="ad-inbox-card" lang="{{ $lang }}" dir="{{ $lang === 'ar' ? 'rtl' : 'ltr' }}" style="display:flex;gap:10px;padding:12px;border:1px solid var(--border-default);border-radius:12px;margin-block-end:10px">
                        <span class="ad-avatar" aria-hidden="true">R</span>
                        <div style="min-inline-size:0">
                            <div style="font-weight:700">{{ $previewTitle !== '' ? $previewTitle : '—' }}</div>
                            <div class="ad-muted" style="font-size:13px">{{ \Illuminate\Support\Str::limit($previewBody, 90) ?: '—' }}</div>
                        </div>
                    </div>
                @endforeach
            </x-admin.card>
        </div>

    @elseif ($tab === 'sent')
        <x-admin.card flush>
            @if ($sent->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/communication.none_sent')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/communication.announcement') }}</th>
                        <th>{{ __('admin/communication.audience') }}</th>
                        <th class="ad-end">{{ __('admin/communication.reach_label') }}</th>
                        <th>{{ __('admin/communication.sent') }}</th>
                        <th>{{ __('admin/communication.by') }}</th>
                        @if ($mayWrite)<th><span class="ad-sr">{{ __('admin/communication.actions') }}</span></th>@endif
                    </tr></thead>
                    <tbody>
                    @foreach ($sent as $item)
                        <tr wire:key="an-{{ $item->id }}">
                            <td data-label="{{ __('admin/communication.announcement') }}">
                                <bdi style="font-weight:600">{{ app()->getLocale() === 'ar' ? $item->title_ar : $item->title_en }}</bdi>
                                <div class="ad-muted" style="font-size:12.5px"><bdi>{{ \Illuminate\Support\Str::limit(app()->getLocale() === 'ar' ? $item->body_ar : $item->body_en, 80) }}</bdi></div>
                            </td>
                            <td data-label="{{ __('admin/communication.audience') }}">{{ __('admin/communication.audiences.'.$item->audience->value) }}</td>
                            <td data-label="{{ __('admin/communication.reach_label') }}" class="ad-end ad-num">{{ $item->sent_at ? number_format($item->reach) : '—' }}</td>
                            <td data-label="{{ __('admin/communication.sent') }}" class="ad-num">
                                @if ($item->sent_at)
                                    {{ $item->sent_at->timezone('Africa/Cairo')->format('D j M H:i') }}
                                @elseif ($item->queued_at)
                                    <span class="ad-chip ad-chip--warn">{{ __('admin/communication.sending') }}</span>
                                @else
                                    <span class="ad-chip">{{ __('admin/communication.scheduled_for', ['at' => $item->scheduled_for?->timezone('Africa/Cairo')->format('D j M H:i')]) }}</span>
                                @endif
                            </td>
                            <td data-label="{{ __('admin/communication.by') }}">{{ $item->author?->name ?? '—' }}</td>
                            @if ($mayWrite)
                                <td class="ad-end">
                                    @if (! $item->queued_at)
                                        <button type="button" class="ad-btn ad-btn--sm" wire:click="cancel('{{ $item->id }}')" wire:confirm="{{ __('admin/communication.cancel_confirm') }}">{{ __('admin/communication.cancel') }}</button>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <x-rq-pager :page="$sent" />
            @endif
        </x-admin.card>

    @elseif ($tab === 'failed')
        <x-admin.card flush>
            @if ($failed->isEmpty())
                <x-admin.state kind="empty" :title="__('admin/communication.none_failed')" />
            @else
                <div class="ad-table-wrap"><table class="ad-table ad-table--cards">
                    <thead><tr>
                        <th>{{ __('admin/communication.type') }}</th>
                        <th>{{ __('admin/communication.person') }}</th>
                        <th>{{ __('admin/communication.result') }}</th>
                        <th>{{ __('admin/communication.time') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($failed as $row)
                        <tr wire:key="fl-{{ $row->id }}">
                            <td data-label="{{ __('admin/communication.type') }}"><span class="ad-code">{{ $row->type instanceof \BackedEnum ? $row->type->value : $row->type }}</span></td>
                            <td data-label="{{ __('admin/communication.person') }}">
                                @if ($row->user)
                                    <a class="ad-link" href="{{ route('admin.members.show', $row->user_id) }}" wire:navigate><bdi>{{ $row->user->full_name }}</bdi></a>
                                @else — @endif
                            </td>
                            <td data-label="{{ __('admin/communication.result') }}"><span class="ad-chip ad-chip--bad">{{ \Illuminate\Support\Str::limit($row->failed_reason, 60) }}</span></td>
                            <td data-label="{{ __('admin/communication.time') }}" class="ad-num ad-muted">{{ $row->created_at?->timezone('Africa/Cairo')->format('D j M H:i') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <x-rq-pager :page="$failed" />
            @endif
        </x-admin.card>
    @endif
</div>
