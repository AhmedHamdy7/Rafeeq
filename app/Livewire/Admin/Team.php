<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\ManageTeamAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\AdminRole;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * TEAM AND ROLES (M): every staff account, its role and state, and — for Admins — invite,
 * change role, reset access, suspend, reactivate. The rules live in ManageTeamAction;
 * this page only collects the input and shows the one-time link.
 */
#[Layout('components.layouts.admin')]
class Team extends Component
{
    public bool $inviting = false;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    /** The one-time link just made, shown once and never stored in the clear. */
    public ?string $link = null;

    public ?string $linkFor = null;

    public ?string $editing = null;

    public string $newRole = '';

    public function mount(): void
    {
        $this->authorizeToManage();
    }

    public function startInvite(): void
    {
        $this->resetValidation();
        $this->reset('name', 'email', 'role', 'link', 'linkFor', 'editing');
        $this->inviting = true;
    }

    public function invite(ManageTeamAction $action): void
    {
        $this->authorizeToManage();

        $this->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'role' => ['required', 'string', Rule::enum(AdminRole::class)],
        ], attributes: [
            'name' => __('admin/team.name'),
            'email' => __('admin/team.email'),
            'role' => __('admin/team.role'),
        ]);

        $result = $action->invite($this->admin(), $this->name, $this->email, AdminRole::from($this->role));

        $this->inviting = false;
        $this->link = $result['link'];
        $this->linkFor = $result['admin']->name;
    }

    public function resetAccess(string $id, ManageTeamAction $action): void
    {
        $target = $this->member($id);
        $this->link = $action->resetAccess($this->admin(), $target);
        $this->linkFor = $target->name;
    }

    public function editRole(string $id): void
    {
        $this->authorizeToManage();
        $this->resetValidation();
        $this->editing = $id;
        $this->newRole = (string) $this->member($id)->getRoleNames()->first();
    }

    public function saveRole(ManageTeamAction $action): void
    {
        $this->validate(['newRole' => ['required', 'string', Rule::enum(AdminRole::class)]]);

        $action->changeRole($this->admin(), $this->member((string) $this->editing), AdminRole::from($this->newRole));
        $this->reset('editing', 'newRole');
    }

    public function suspend(string $id, ManageTeamAction $action): void
    {
        $action->suspend($this->admin(), $this->member($id));
    }

    public function reactivate(string $id, ManageTeamAction $action): void
    {
        $action->reactivate($this->admin(), $this->member($id));
    }

    public function dismissLink(): void
    {
        $this->reset('link', 'linkFor');
    }

    private function member(string $id): AdminUser
    {
        return AdminUser::query()->whereKey($id)->first() ?? abort(404);
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    private function authorizeToManage(): void
    {
        abort_unless($this->admin()->can(AdminPermission::AdminManage->value), 403);
    }

    public function render()
    {
        return view('livewire.admin.team', [
            'staff' => AdminUser::query()->with('roles')->orderByRaw("case status when 'active' then 0 when 'invited' then 1 else 2 end")->orderBy('name')->get(),
            'roles' => AdminRole::cases(),
            'me' => $this->admin(),
        ])->title(__('admin/team.title'));
    }
}
