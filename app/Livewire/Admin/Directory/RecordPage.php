<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminAction;
use App\Domains\Admin\Models\AdminUser;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * What the directory's detail pages (E03, C02, Y02, B03) share: who is signed in, what they
 * may do, and the staff actions already taken on the record, newest first.
 */
abstract class RecordPage extends Component
{
    public string $reason = '';

    public ?string $done = null;

    protected function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    protected function may(AdminPermission $permission): bool
    {
        return Auth::guard('admin')->user()?->can($permission->value) === true;
    }

    protected function authorizeTo(AdminPermission $permission): void
    {
        abort_unless($this->may($permission), 403);
    }

    protected function requireReason(): void
    {
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:255']], attributes: ['reason' => __('admin/actions.reason')]);
    }

    /** @return Collection<int, AdminAction> */
    protected function staffActions(string $entityId): Collection
    {
        return AdminAction::query()->where('entity_id', $entityId)->with('admin')->latest('created_at')->latest('id')->limit(20)->get();
    }
}
