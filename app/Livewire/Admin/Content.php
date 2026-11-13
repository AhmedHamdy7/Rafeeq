<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\ContentPage;
use App\Domains\Admin\Support\AdminActionLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * CONTENT (J01): the texts the app shows that are not code — help, how to reach support,
 * terms and privacy, community guidelines — in Arabic and English. Each save is a new
 * version and an audit row with the old and new text, so "what did the terms say in
 * November" has an answer (Q-055).
 */
#[Layout('components.layouts.admin')]
class Content extends Component
{
    public ?string $editing = null;

    public string $bodyAr = '';

    public string $bodyEn = '';

    public ?string $done = null;

    public function mount(): void
    {
        abort_unless($this->admin()->can(AdminPermission::SettingsManage->value), 403);
    }

    public function edit(string $key): void
    {
        abort_unless(in_array($key, ContentPage::KEYS, true), 404);

        $page = ContentPage::query()->find($key);
        $this->resetValidation();
        $this->editing = $key;
        $this->bodyAr = (string) $page?->body_ar;
        $this->bodyEn = (string) $page?->body_en;
        $this->done = null;
    }

    public function save(): void
    {
        abort_unless($this->editing !== null && in_array($this->editing, ContentPage::KEYS, true), 404);

        $this->validate([
            'bodyAr' => ['required', 'string', 'max:20000'],
            'bodyEn' => ['required', 'string', 'max:20000'],
        ], attributes: ['bodyAr' => __('admin/communication.body_ar'), 'bodyEn' => __('admin/communication.body_en')]);

        $admin = $this->admin();
        $key = $this->editing;

        DB::transaction(function () use ($admin, $key): void {
            $page = ContentPage::query()->lockForUpdate()->find($key);
            $before = $page === null ? null : ['version' => $page->version, 'body_ar' => $page->body_ar, 'body_en' => $page->body_en];

            $page ??= new ContentPage(['key' => $key, 'version' => 0]);
            $page->forceFill([
                'body_ar' => trim($this->bodyAr),
                'body_en' => trim($this->bodyEn),
                'version' => (int) $page->version + 1,
                'updated_by' => $admin->getKey(),
            ])->save();

            AdminActionLog::record($admin, 'content.update', $page, $before, ['version' => $page->version, 'body_ar' => $page->body_ar, 'body_en' => $page->body_en]);
        });

        $this->done = __('admin/communication.content_saved');
        $this->reset('editing', 'bodyAr', 'bodyEn');
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    public function render()
    {
        return view('livewire.admin.content', [
            'pages' => ContentPage::query()->with('editor')->get()->keyBy('key'),
            'keys' => ContentPage::KEYS,
        ])->title(__('admin/communication.content_title'));
    }
}
