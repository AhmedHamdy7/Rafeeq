{{-- CONTENT (J01). Arabic and English, a new version on every save, written to the audit log. --}}
<div>
    <x-admin.page-head :title="__('admin/communication.content_title')" :sub="__('admin/communication.content_sub')" />

    @if ($done)
        <p class="ad-flash" role="status"><x-admin.icon name="check" />{{ $done }}</p>
    @endif

    <div class="ad-grid ad-grid--2" style="align-items:start">
        @foreach ($keys as $key)
            @php($page = $pages->get($key))
            <x-admin.card :title="__('admin/communication.pages.'.$key)" bar wire:key="cp-{{ $key }}">
                @if ($editing === $key)
                    <form wire:submit="save">
                        <div class="ad-field">
                            <label class="ad-label" for="cp-ar-{{ $key }}">{{ __('admin/communication.body_ar') }} *</label>
                            <textarea id="cp-ar-{{ $key }}" class="ad-textarea" rows="8" dir="rtl" lang="ar" wire:model="bodyAr" required></textarea>
                            @error('bodyAr') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ad-field">
                            <label class="ad-label" for="cp-en-{{ $key }}">{{ __('admin/communication.body_en') }} *</label>
                            <textarea id="cp-en-{{ $key }}" class="ad-textarea" rows="8" dir="ltr" lang="en" wire:model="bodyEn" required></textarea>
                            @error('bodyEn') <span class="ad-error">{{ $message }}</span> @enderror
                        </div>
                        <div class="ad-row">
                            <button type="submit" class="ad-btn ad-btn--primary" wire:loading.attr="disabled">{{ __('admin/communication.publish_version', ['version' => ($page?->version ?? 0) + 1]) }}</button>
                            <button type="button" class="ad-btn" wire:click="$set('editing', null)">{{ __('admin.queue.cancel') }}</button>
                        </div>
                    </form>
                @elseif ($page === null)
                    <x-admin.state kind="empty" :title="__('admin/communication.not_written')" />
                    <button type="button" class="ad-btn ad-btn--sm" wire:click="edit('{{ $key }}')">{{ __('admin/communication.write') }}</button>
                @else
                    <p class="ad-muted" style="font-size:12.5px;margin:0 0 8px">
                        {{ __('admin/communication.version', ['version' => $page->version, 'at' => $page->updated_at?->timezone('Africa/Cairo')->format('j M Y H:i'), 'name' => $page->editor?->name ?? '—']) }}
                    </p>
                    <div dir="auto" style="white-space:pre-line;max-block-size:180px;overflow:auto">{{ \Illuminate\Support\Str::limit(app()->getLocale() === 'ar' ? $page->body_ar : $page->body_en, 600) }}</div>
                    <div class="ad-row" style="margin-block-start:10px">
                        <button type="button" class="ad-btn ad-btn--sm" wire:click="edit('{{ $key }}')">{{ __('admin/communication.edit') }}</button>
                    </div>
                @endif
            </x-admin.card>
        @endforeach
    </div>

    <p class="ad-hint" style="margin-block-start:12px">{{ __('admin/communication.content_app_note') }}</p>
</div>
