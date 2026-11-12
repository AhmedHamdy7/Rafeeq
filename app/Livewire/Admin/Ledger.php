<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * LEDGER (G): every money line, newest first — commissions, fees, payments, coupons,
 * credits, write-offs — with the running balance it left. Read-only: the ledger is
 * append-only, and a correction is a new line (Payments page), never an edit here.
 */
#[Layout('components.layouts.admin')]
class Ledger extends Component
{
    use WithPagination;

    #[Url(as: 'side')]
    public string $side = '';

    #[Url(as: 'type')]
    public string $type = '';

    #[Url(as: 'member')]
    public string $member = '';

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can(AdminPermission::PaymentView->value) === true, 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $search = trim($this->member);

        $entries = DebtEntry::query()
            ->with(['user', 'createdBy'])
            ->when(DebtSide::tryFrom($this->side), fn (Builder $query, DebtSide $side) => $query->where('side', $side->value))
            ->when(DebtEntryType::tryFrom($this->type), fn (Builder $query, DebtEntryType $type) => $query->where('type', $type->value))
            ->when($search !== '', fn (Builder $query) => $query->whereHas('user', function (Builder $user) use ($search) {
                // Literal search: a % or _ typed by staff is a character, not a wildcard.
                $like = '%'.addcslashes($search, '%_\\').'%';
                $digits = preg_replace('/\D/', '', $search);

                $user->where('full_name', 'like', $like)
                    ->orWhere('id', $search)
                    ->when(strlen((string) $digits) >= 4, fn (Builder $q) => $q->orWhere('phone_e164', 'like', '%'.$digits));
            }))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.admin.ledger', [
            'entries' => $entries,
            'types' => DebtEntryType::cases(),
        ])->title(__('admin/money.ledger.title'));
    }
}
