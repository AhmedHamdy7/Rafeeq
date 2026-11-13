<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Models\Coupon;
use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * COUPONS (K): the coupons issued to the harmed side of a collected fee, newest first —
 * who got one, worth what, for which fee, by whose hand and why. Issuing one stays on
 * Payments, beside the fee lines it is issued from.
 */
#[Layout('components.layouts.admin')]
class Coupons extends Component
{
    use WithPagination;

    /** K03 coupons issued, or K04 credits added by hand. */
    #[Url(as: 'view')]
    public string $view = 'coupons';

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can(AdminPermission::PaymentView->value) === true, 403);
    }

    public function render()
    {
        return view('livewire.admin.coupons', [
            'coupons' => $this->view === 'credits' ? null : Coupon::query()->with(['user', 'issuedBy'])->orderByDesc('created_at')->orderByDesc('id')->paginate(25),
            'credits' => $this->view === 'credits'
                ? DebtEntry::query()->where('type', DebtEntryType::Credit->value)->with(['user', 'createdBy'])->orderByDesc('created_at')->orderByDesc('id')->paginate(25)
                : null,
            'mayIssue' => Auth::guard('admin')->user()?->can(AdminPermission::DebtManage->value) === true,
        ])->title(__('admin/money.coupons.title'));
    }
}
