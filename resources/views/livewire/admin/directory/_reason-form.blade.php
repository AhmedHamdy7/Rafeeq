{{-- A confirm panel with a required reason. Needs $title, $hint, $submit, $confirm, $cancel; optional $danger. --}}
<x-admin.card :title="$title" bar style="margin-block-end:12px">
    <form wire:submit="{{ $submit }}">
        @error('record') <p class="ad-flash ad-flash--error"><x-admin.icon name="alert" />{{ $message }}</p> @enderror
        <p class="ad-hint" style="margin:0 0 12px">{{ $hint }}</p>
        <div class="ad-field">
            <label class="ad-label" for="rec-reason">{{ __('admin/actions.reason') }}</label>
            <textarea id="rec-reason" class="ad-textarea" rows="2" dir="auto" wire:model="reason" required></textarea>
            @error('reason') <span class="ad-error">{{ $message }}</span> @enderror
        </div>
        <div class="ad-row">
            <button type="submit" @class(['ad-btn', 'ad-btn--danger' => $danger ?? false, 'ad-btn--primary' => ! ($danger ?? false)]) wire:loading.attr="disabled">{{ $confirm }}</button>
            <button type="button" class="ad-btn" wire:click="{{ $cancel }}">{{ __('admin.queue.cancel') }}</button>
        </div>
    </form>
</x-admin.card>
