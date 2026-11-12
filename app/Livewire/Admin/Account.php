<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\ChangeAdminPasswordAction;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * My account (MY1): who I am, my language and theme, my password, and the last things
 * I did. Open to every signed-in staff member — it only ever shows the viewer's own
 * account, so it needs no permission beyond being signed in.
 *
 * Name and email are read-only here: another Admin changes them (the design's note), so
 * a stolen session cannot quietly move the account to an address the thief controls.
 */
#[Layout('components.layouts.admin')]
class Account extends Component
{
    public bool $changing = false;

    /** Set after a successful change; the layout's flash is not redrawn by a Livewire update. */
    public bool $changed = false;

    public string $current = '';

    public string $new = '';

    public string $new_confirmation = '';

    public function startPasswordChange(): void
    {
        $this->resetValidation();
        $this->reset('current', 'new', 'new_confirmation', 'changed');
        $this->changing = true;
    }

    public function cancelPasswordChange(): void
    {
        $this->resetValidation();
        $this->reset('changing', 'current', 'new', 'new_confirmation');
    }

    public function changePassword(ChangeAdminPasswordAction $action): void
    {
        $this->validate([
            'current' => ['required', 'string'],
            'new' => ['required', 'string', 'min:'.ChangeAdminPasswordAction::MIN_LENGTH, 'max:200', 'confirmed'],
        ], attributes: [
            'current' => __('admin/account.password.current'),
            'new' => __('admin/account.password.new'),
        ]);

        $action->execute($this->admin(), $this->current, $this->new);

        // A new password is a new session: an id copied before the change is worth nothing after it.
        session()->regenerate();

        $this->reset('changing', 'current', 'new', 'new_confirmation');
        $this->changed = true;
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    public function render()
    {
        $admin = $this->admin();

        return view('livewire.admin.account', [
            'admin' => $admin,
            'role' => $admin->getRoleNames()->first(),
            'recent' => AdminAction::query()
                ->where('admin_id', $admin->id)
                ->latest('created_at')
                ->latest('id')
                ->limit(8)
                ->get(['id', 'action', 'created_at']),
        ])->title(__('admin/account.title'));
    }
}
