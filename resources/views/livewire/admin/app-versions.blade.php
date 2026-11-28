{{--
    APP VERSIONS. One card per store (iOS, Android), side by side: who is on which version, then
    the release policy — latest, minimum, store link, what's new — the two switches, and a reason.
--}}
<div>
    <x-admin.page-head :title="__('admin/app_versions.title')" :sub="__('admin/app_versions.sub')" />

    <div class="ad-appv-grid">
        @foreach ($platforms as $platform => ['release' => $release, 'spread' => $spread])
            @php($form = $forms[$platform])
            <section class="ad-appv" wire:key="appv-{{ $platform }}">
                <header class="ad-appv__head">
                    <span class="ad-appv__badge ad-appv__badge--{{ $platform }}" aria-hidden="true">{{ $platform === 'ios' ? 'iOS' : 'AND' }}</span>
                    <div style="flex:1;min-inline-size:0">
                        <h2 class="ad-appv__title">{{ __('admin/app_versions.platforms.'.$platform) }}</h2>
                        <div class="ad-muted" style="font-size:12.5px">
                            @if ($release)
                                {{ __('admin/app_versions.changed_by', ['name' => $release->updatedBy?->name ?? '—', 'when' => $release->updated_at?->diffForHumans() ?? '—']) }}
                            @else
                                {{ __('admin/app_versions.not_set') }}
                            @endif
                        </div>
                    </div>
                    @if ($release?->force_update)
                        <span class="ad-chip ad-chip--bad">{{ __('admin/app_versions.force_on') }}</span>
                    @elseif ($release)
                        <span class="ad-chip ad-chip--ok">v{{ $release->latest_version }}</span>
                    @endif
                </header>

                {{-- Who is on what, from the phones members signed in with. --}}
                <div class="ad-appv__stats">
                    <div><span class="ad-appv__num ad-num">{{ number_format($spread['total']) }}</span><span class="ad-hint">{{ __('admin/app_versions.spread.total') }}</span></div>
                    <div><span class="ad-appv__num ad-num is-ok">{{ number_format($spread['latest']) }}</span><span class="ad-hint">{{ __('admin/app_versions.spread.latest') }}</span></div>
                    <div><span class="ad-appv__num ad-num is-warn">{{ number_format($spread['older']) }}</span><span class="ad-hint">{{ __('admin/app_versions.spread.older') }}</span></div>
                    <div><span class="ad-appv__num ad-num">{{ number_format($spread['unknown']) }}</span><span class="ad-hint">{{ __('admin/app_versions.spread.unknown') }}</span></div>
                </div>

                <form wire:submit="save('{{ $platform }}')" class="ad-appv__form">
                    <div class="ad-appv__pair">
                        <div class="ad-field">
                            <label class="ad-label" for="latest-{{ $platform }}">{{ __('admin/app_versions.latest') }}</label>
                            <input id="latest-{{ $platform }}" class="ad-input ad-num" dir="ltr" placeholder="1.4.0" wire:model="forms.{{ $platform }}.latest">
                            <span class="ad-hint">{{ __('admin/app_versions.latest_hint') }}</span>
                            @error("forms.{$platform}.latest") <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ad-field">
                            <label class="ad-label" for="minimum-{{ $platform }}">{{ __('admin/app_versions.minimum') }}</label>
                            <input id="minimum-{{ $platform }}" class="ad-input ad-num" dir="ltr" placeholder="1.2.0" wire:model="forms.{{ $platform }}.minimum">
                            <span class="ad-hint">{{ __('admin/app_versions.minimum_hint') }}</span>
                            @error("forms.{$platform}.minimum") <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                    </div>

                    <div class="ad-field">
                        <label class="ad-label" for="store-{{ $platform }}">{{ __('admin/app_versions.store_url') }}</label>
                        <div class="ad-row" style="gap:8px;flex-wrap:nowrap">
                            <input id="store-{{ $platform }}" class="ad-input" type="url" dir="ltr" style="flex:1"
                                   placeholder="{{ $platform === 'ios' ? 'https://apps.apple.com/app/id…' : 'https://play.google.com/store/apps/details?id=…' }}"
                                   wire:model="forms.{{ $platform }}.store_url">
                            @if ($release?->store_url)
                                <a class="ad-btn ad-btn--sm" href="{{ $release->store_url }}" target="_blank" rel="noopener">{{ __('admin/app_versions.open') }}</a>
                            @endif
                        </div>
                        @error("forms.{$platform}.store_url") <span class="ad-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="ad-appv__pair">
                        <div class="ad-field">
                            <label class="ad-label" for="notes-ar-{{ $platform }}">{{ __('admin/app_versions.notes_ar') }}</label>
                            <textarea id="notes-ar-{{ $platform }}" class="ad-textarea" rows="3" dir="rtl" wire:model="forms.{{ $platform }}.notes_ar"></textarea>
                        </div>
                        <div class="ad-field">
                            <label class="ad-label" for="notes-en-{{ $platform }}">{{ __('admin/app_versions.notes_en') }}</label>
                            <textarea id="notes-en-{{ $platform }}" class="ad-textarea" rows="3" dir="ltr" wire:model="forms.{{ $platform }}.notes_en"></textarea>
                        </div>
                    </div>

                    <label @class(['ad-appv__switch', 'is-on is-danger' => $form['force']])>
                        <input type="checkbox" wire:model.live="forms.{{ $platform }}.force">
                        <span>
                            <strong>{{ __('admin/app_versions.force') }}</strong>
                            <span class="ad-hint">{{ __('admin/app_versions.force_hint') }}</span>
                        </span>
                    </label>

                    <label @class(['ad-appv__switch', 'is-on' => $form['notify']])>
                        <input type="checkbox" wire:model.live="forms.{{ $platform }}.notify">
                        <span>
                            <strong>{{ __('admin/app_versions.notify') }}</strong>
                            <span class="ad-hint">{{ __('admin/app_versions.notify_hint') }}</span>
                            @if ($release?->notified_version)
                                <span class="ad-hint" style="display:block">{{ __('admin/app_versions.last_notified', ['version' => $release->notified_version, 'count' => number_format($release->notified_count), 'when' => $release->notified_at?->diffForHumans() ?? '—']) }}</span>
                            @endif
                        </span>
                    </label>

                    <div class="ad-field">
                        <label class="ad-label" for="reason-{{ $platform }}">{{ __('admin/app_versions.reason') }}</label>
                        <textarea id="reason-{{ $platform }}" class="ad-textarea" rows="2" dir="auto" wire:model="forms.{{ $platform }}.reason"
                                  placeholder="{{ __('admin/app_versions.reason_placeholder') }}"></textarea>
                        @error("forms.{$platform}.reason") <span class="ad-error">{{ $message }}</span> @enderror
                    </div>

                    <div class="ad-row">
                        <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled" wire:target="save('{{ $platform }}')"
                                @if ($form['force'] || $form['notify']) wire:confirm="{{ __('admin/app_versions.confirm') }}" @endif>
                            {{ __('admin/app_versions.save') }}
                        </button>
                    </div>
                </form>
            </section>
        @endforeach
    </div>

    {{-- How the app reads it — so staff know what each number does before they change it. --}}
    <x-admin.card :title="__('admin/app_versions.rules_title')" bar>
        <ul class="ad-appv__rules">
            <li><span class="ad-chip ad-chip--bad">{{ __('admin/app_versions.rule_required') }}</span> {{ __('admin/app_versions.rule_required_text') }}</li>
            <li><span class="ad-chip ad-chip--warn">{{ __('admin/app_versions.rule_suggested') }}</span> {{ __('admin/app_versions.rule_suggested_text') }}</li>
            <li><span class="ad-chip ad-chip--ok">{{ __('admin/app_versions.rule_ok') }}</span> {{ __('admin/app_versions.rule_ok_text') }}</li>
        </ul>
    </x-admin.card>
</div>
