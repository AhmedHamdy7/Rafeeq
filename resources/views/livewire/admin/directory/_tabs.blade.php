{{-- Status tabs shared by the directory pages. Needs $tabs, $status, $section. --}}
<div class="ad-tabs" role="tablist">
    <button type="button" role="tab" class="ad-tab" aria-selected="{{ $status === 'all' ? 'true' : 'false' }}" wire:click="pick('all')">{{ __('admin/directory.all') }}</button>
    @foreach ($tabs as $name)
        <button type="button" role="tab" class="ad-tab" aria-selected="{{ $status === $name ? 'true' : 'false' }}" wire:click="pick('{{ $name }}')">{{ __('admin/directory.statuses.'.$name) }}</button>
    @endforeach
</div>
