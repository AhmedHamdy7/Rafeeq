{{--
    /admin/_components — every dashboard component in one page, to check a change in both
    themes (moon / sun in the top bar), both languages (globe) and at phone width before
    it reaches a real page. Static on purpose: no data, nothing to break.
--}}
<x-layouts.admin :title="__('admin/components.title')">
    <x-admin.page-head :title="__('admin/components.title')" :sub="__('admin/components.sub')">
        <button type="button" class="ad-btn"><x-admin.icon name="columns" />{{ __('admin/components.customise') }}</button>
    </x-admin.page-head>

    <div class="ad-section-label">{{ __('admin/components.buttons') }}</div>
    <div class="ad-row">
        <button type="button" class="ad-btn ad-btn--primary">{{ __('admin/components.primary') }}</button>
        <button type="button" class="ad-btn">{{ __('admin/components.secondary') }}</button>
        <button type="button" class="ad-btn ad-btn--danger">{{ __('admin/components.danger') }}</button>
        <button type="button" class="ad-btn ad-btn--dangerline">{{ __('admin/components.danger') }}</button>
        <button type="button" class="ad-btn ad-btn--quiet">{{ __('admin/components.quiet') }}</button>
        <button type="button" class="ad-btn ad-btn--sm">{{ __('admin/components.small') }}</button>
        <button type="button" class="ad-btn ad-btn--xs">{{ __('admin/components.tiny') }}</button>
        <button type="button" class="ad-btn ad-btn--primary" disabled>{{ __('admin/components.disabled') }}</button>
        <a href="#" class="ad-link">{{ __('admin/components.link') }} <x-admin.icon name="chevron" size="12" class="ad-flip" /></a>
    </div>

    <div class="ad-section-label">{{ __('admin/components.chips') }}</div>
    <div class="ad-row">
        <span class="ad-chip ad-chip--ok">{{ __('admin/components.on_time') }}</span>
        <span class="ad-chip ad-chip--bad">{{ __('admin/components.overdue', ['count' => 2]) }}</span>
        <span class="ad-chip ad-chip--warn">{{ __('admin/components.due_soon') }}</span>
        <span class="ad-chip ad-chip--info">{{ __('admin/components.info') }}</span>
        <span class="ad-chip">{{ __('admin/components.neutral') }}</span>
        <span class="ad-code">PAY-12</span>
        <span class="ad-role-tag"><x-admin.icon name="lock" size="12" />{{ __('admin/shell.roles.super_admin') }}</span>
        <span class="ad-badge ad-badge--bad">7</span>
        <span class="ad-badge ad-badge--warn">9</span>
        <span class="ad-badge">4</span>
    </div>

    <div class="ad-section-label">{{ __('admin/components.tiles') }}</div>
    <div class="ad-grid ad-grid--4">
        <x-admin.card flush tone="alert">
            <x-admin.stat :label="__('admin/components.open_sos')" value="1" :chip="__('admin/components.nobody_on_it')" chip-tone="bad">
            </x-admin.stat>
        </x-admin.card>
        <x-admin.card flush>
            <x-admin.stat :label="__('admin/components.applications')" value="7" :chip="__('admin/components.overdue', ['count' => 1])" chip-tone="bad" :foot="__('admin/components.oldest', ['age' => '<strong>26h 10m</strong>'])" />
        </x-admin.card>
        <x-admin.card flush>
            <x-admin.stat :label="__('admin/components.payments')" value="6" :chip="__('admin/components.on_time')" :locked="__('admin/shell.roles.super_admin')" :foot="__('admin/components.oldest', ['age' => '<strong>19h</strong>'])" />
        </x-admin.card>
        <x-admin.card flush tone="topline-bad">
            <x-admin.stat :label="__('admin/components.overdue_money')" value="EGP 2,960.00" money>
                <span class="ad-trend ad-trend--down">▲ 8%</span>
            </x-admin.stat>
        </x-admin.card>
    </div>

    <div class="ad-section-label">{{ __('admin/components.table') }}</div>
    <x-admin.card flush>
        <div class="ad-table-wrap">
            <table class="ad-table ad-table--cards">
                <thead>
                <tr>
                    <th>{{ __('admin/components.col_who') }}</th>
                    <th>{{ __('admin/components.col_status') }}</th>
                    <th>{{ __('admin/components.col_rule') }}</th>
                    <th class="ad-end">{{ __('admin/components.col_amount') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ([['Karim F.', 'ok', 'PAY-12', '198.00'], ['Salma K.', 'bad', 'PAY-17', '1,240.50'], ['Yasmin A.', 'warn', 'PROF-33', '0.00']] as [$who, $tone, $rule, $amount])
                    <tr class="ad-click">
                        <td data-label="{{ __('admin/components.col_who') }}"><strong>{{ $who }}</strong></td>
                        <td data-label="{{ __('admin/components.col_status') }}"><span class="ad-chip ad-chip--{{ $tone }}">{{ __('admin/components.tone_'.$tone) }}</span></td>
                        <td data-label="{{ __('admin/components.col_rule') }}"><span class="ad-code">{{ $rule }}</span></td>
                        <td data-label="{{ __('admin/components.col_amount') }}" class="ad-end ad-num">EGP {{ $amount }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-admin.card>

    <div class="ad-section-label">{{ __('admin/components.controls') }}</div>
    <div class="ad-grid ad-grid--2">
        <x-admin.card :title="__('admin/components.form')">
            <div class="ad-field">
                <label class="ad-label" for="g-reason">{{ __('admin/components.reason') }}</label>
                <select id="g-reason" class="ad-select">
                    <option>{{ __('admin/components.reason_a') }}</option>
                    <option>{{ __('admin/components.reason_b') }}</option>
                </select>
            </div>
            <div class="ad-field">
                <label class="ad-label" for="g-note">{{ __('admin/components.note') }}</label>
                <textarea id="g-note" class="ad-textarea" aria-invalid="true"></textarea>
                <span class="ad-error">{{ __('admin/components.note_required') }}</span>
            </div>
            <label class="ad-check"><input type="checkbox" checked> {{ __('admin/components.notify') }}</label>
        </x-admin.card>
        <x-admin.card :title="__('admin/components.filters')">
            <div class="ad-tabs" role="tablist">
                <button type="button" class="ad-tab" role="tab" aria-selected="true">{{ __('admin/components.tab_all') }} <span class="ad-badge">24</span></button>
                <button type="button" class="ad-tab" role="tab" aria-selected="false">{{ __('admin/components.tab_open') }}</button>
                <button type="button" class="ad-tab" role="tab" aria-selected="false">{{ __('admin/components.tab_closed') }}</button>
            </div>
            <div class="ad-toolbar">
                <div class="ad-seg">
                    <button type="button" aria-pressed="true">{{ __('admin/components.today') }}</button>
                    <button type="button" aria-pressed="false">{{ __('admin/components.week') }}</button>
                    <button type="button" aria-pressed="false">{{ __('admin/components.month') }}</button>
                </div>
                <button type="button" class="ad-fchip" aria-pressed="true">{{ __('admin/components.women_only') }}</button>
                <button type="button" class="ad-fchip" aria-pressed="false">+ {{ __('admin/components.add_filter') }}</button>
            </div>
            <dl class="ad-kv">
                <dt>{{ __('admin/components.col_who') }}</dt><dd>Salma K. · <span class="ad-num">+20 10 1234 5678</span></dd>
                <dt>{{ __('admin/components.col_rule') }}</dt><dd><span class="ad-code">COM-45</span></dd>
            </dl>
        </x-admin.card>
    </div>

    <div class="ad-section-label">{{ __('admin/components.states') }}</div>
    <div class="ad-grid ad-grid--4">
        <x-admin.card flush><x-admin.state kind="empty" :text="__('admin/components.empty_text')" /></x-admin.card>
        <x-admin.card flush><x-admin.state kind="loading" /></x-admin.card>
        <x-admin.card flush><x-admin.state kind="error"><button type="button" class="ad-btn ad-btn--sm">{{ __('admin/shell.states.retry') }}</button></x-admin.state></x-admin.card>
        <x-admin.card flush><x-admin.state kind="locked" /></x-admin.card>
    </div>

    <div class="ad-section-label">{{ __('admin/components.messages') }}</div>
    <div class="ad-stack">
        <p class="ad-flash" style="margin:0"><x-admin.icon name="check" />{{ __('admin/components.saved') }}</p>
        <p class="ad-flash ad-flash--error" style="margin:0"><x-admin.icon name="alert" />{{ __('admin/components.failed') }}</p>
        <div class="ad-notice ad-notice--warn"><x-admin.icon name="info" />{{ __('admin/components.notice') }}</div>
    </div>

    <div class="ad-section-label">{{ __('admin/components.modal') }}</div>
    <div x-data="{ open: false }">
        <button type="button" class="ad-btn" x-on:click="open = true">{{ __('admin/components.open_modal') }}</button>
        <div class="ad-dim" x-show="open" x-cloak x-on:click.self="open = false" x-on:keydown.escape.window="open = false">
            <div class="ad-modal" role="dialog" aria-modal="true" aria-labelledby="g-modal-title">
                <h2 class="ad-modal__title" id="g-modal-title">{{ __('admin/components.modal_title') }}</h2>
                <p class="ad-muted" style="margin:0">{{ __('admin/components.modal_text') }}</p>
                <div class="ad-modal__actions">
                    <button type="button" class="ad-btn" x-on:click="open = false">{{ __('admin/components.cancel') }}</button>
                    <button type="button" class="ad-btn ad-btn--danger" x-on:click="open = false">{{ __('admin/components.confirm') }}</button>
                </div>
            </div>
        </div>
    </div>
</x-layouts.admin>
