<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Actions\ManageDebtAction;
use App\Domains\Admin\Actions\ResolveFeeAppealAction;
use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Payment\Support\DebtStanding;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Trip\Models\MemberWarning;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * PAYMENTS: appeals against passenger fees (SAFE-11, PAY-10); the InstaPay transfers members say they sent, oldest first, with Confirm and Refuse;
 * and the two ways staff take money off a debt by hand — a coupon for the harmed side of a
 * collected fee (PAY-34), and a credit (PAY-10). ADM-03, ADM-04.
 *
 * Read with `payment.view`; every act needs `debt.manage` and is re-checked inside it (see
 * SafetyCases on why a rendered button is not a permission). Refusing, a coupon and a credit each
 * need a written reason; the audit log keeps it.
 */
#[Layout('components.layouts.admin')]
class Payments extends Component
{
    use WithPagination;

    /** The payment whose Refuse form is open. */
    /** I01 the queue, or I04 the history of decided payments. */
    #[Url(as: 'view')]
    public string $view = 'queue';

    public ?string $refusing = null;

    public string $note = '';

    public string $couponFeeLine = '';

    public string $couponNote = '';

    public string $creditUser = '';

    public string $creditSide = 'driver';

    public int $creditPiastres = 0;

    public string $creditNote = '';

    /** The appeal whose decision form is open. */
    public ?string $deciding = null;

    public string $appealNote = '';

    public function mount(): void
    {
        $this->authorizeTo(AdminPermission::PaymentView);
    }

    public function confirm(string $paymentId, ManageDebtAction $action): void
    {
        $this->authorizeTo(AdminPermission::DebtManage);

        try {
            $action->confirmPayment($this->payment($paymentId), $this->admin());
        } catch (DomainException $e) {
            session()->flash('status', __('errors.'.$e->errorCode->value));

            return;
        }

        session()->flash('status', __('admin.payments.confirmed'));
    }

    public function openRefuse(string $paymentId): void
    {
        $this->resetValidation();
        $this->refusing = $paymentId;
        $this->note = '';
    }

    public function refuse(string $paymentId, ManageDebtAction $action): void
    {
        $this->authorizeTo(AdminPermission::DebtManage);

        $this->validate(['note' => ['required', 'string', 'min:5', 'max:255']], attributes: [
            'note' => __('admin.payments.note'),
        ]);

        try {
            $action->rejectPayment($this->payment($paymentId), $this->admin(), $this->note);
        } catch (DomainException $e) {
            $this->addError('note', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('refusing', 'note');
        session()->flash('status', __('admin.payments.refused'));
    }

    public function issueCoupon(ManageDebtAction $action): void
    {
        $this->authorizeTo(AdminPermission::DebtManage);

        $this->validate([
            'couponFeeLine' => ['required', 'string', 'size:26'],
            'couponNote' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: [
            'couponFeeLine' => __('admin.payments.fee_line'),
            'couponNote' => __('admin.payments.note'),
        ]);

        $fee = DebtEntry::query()->whereKey($this->couponFeeLine)->first();

        if ($fee === null) {
            $this->addError('couponFeeLine', __('errors.NOT_FOUND'));

            return;
        }

        try {
            $action->issueCoupon($fee, $this->admin(), $this->couponNote);
        } catch (DomainException $e) {
            $this->addError('couponFeeLine', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('couponFeeLine', 'couponNote');
        session()->flash('status', __('admin.payments.coupon_issued'));
    }

    public function credit(ManageDebtAction $action): void
    {
        $this->authorizeTo(AdminPermission::DebtManage);

        $this->validate([
            'creditUser' => ['required', 'string', 'size:26'],
            'creditSide' => ['required', Rule::enum(DebtSide::class)],
            'creditPiastres' => ['required', 'integer', 'min:1', 'max:10000000'],
            'creditNote' => ['required', 'string', 'min:10', 'max:255'],
        ], attributes: [
            'creditUser' => __('admin.payments.member'),
            'creditPiastres' => __('admin.payments.amount'),
            'creditNote' => __('admin.payments.note'),
        ]);

        $user = User::query()->whereKey($this->creditUser)->first();

        if ($user === null) {
            $this->addError('creditUser', __('errors.NOT_FOUND'));

            return;
        }

        $action->credit($user, DebtSide::from($this->creditSide), $this->creditPiastres, $this->admin(), $this->creditNote);

        $this->reset('creditUser', 'creditPiastres', 'creditNote');
        session()->flash('status', __('admin.payments.credited'));
    }

    public function openAppeal(string $warningId): void
    {
        $this->resetValidation();
        $this->deciding = $warningId;
        $this->appealNote = '';
    }

    /**
     * SAFE-11, PAY-10: overturning takes the warning off her ladder and its fee off her debt.
     */
    public function decideAppeal(string $warningId, bool $overturn, ResolveFeeAppealAction $action): void
    {
        $this->authorizeTo(AdminPermission::DebtManage);

        $this->validate(['appealNote' => ['required', 'string', 'min:10', 'max:255']], attributes: [
            'appealNote' => __('admin.payments.note'),
        ]);

        $warning = MemberWarning::query()->whereKey($warningId)->first() ?? abort(404);

        try {
            $action->execute($warning, $this->admin(), $overturn, $this->appealNote);
        } catch (DomainException $e) {
            $this->addError('appealNote', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('deciding', 'appealNote');
        session()->flash('status', __($overturn ? 'admin.payments.appeal_overturned' : 'admin.payments.appeal_upheld'));
    }

    /**
     * A driver's appeal against one of his fees: overturning credits the whole fee back.
     */
    public function decideDriverAppeal(string $appealId, bool $overturn, ResolveFeeAppealAction $action): void
    {
        $this->authorizeTo(AdminPermission::DebtManage);

        $this->validate(['appealNote' => ['required', 'string', 'min:10', 'max:255']], attributes: [
            'appealNote' => __('admin.payments.note'),
        ]);

        $appeal = FeeAppeal::query()->whereKey($appealId)->first() ?? abort(404);

        try {
            $action->driverFee($appeal, $this->admin(), $overturn, $this->appealNote);
        } catch (DomainException $e) {
            $this->addError('appealNote', __('errors.'.$e->errorCode->value));

            return;
        }

        $this->reset('deciding', 'appealNote');
        session()->flash('status', __($overturn ? 'admin.payments.appeal_overturned' : 'admin.payments.appeal_upheld'));
    }

    private function payment(string $paymentId): DebtPayment
    {
        return DebtPayment::query()->whereKey($paymentId)->first() ?? abort(404);
    }

    private function admin(): ?AdminUser
    {
        return Auth::guard('admin')->user();
    }

    private function authorizeTo(AdminPermission $permission): void
    {
        abort_unless($this->admin()?->can($permission->value) === true, 403);
    }

    public function render()
    {
        $pending = DebtPayment::query()
            ->where('status', DebtPaymentStatus::PendingConfirmation->value)
            ->with('user')
            ->oldest()
            ->orderBy('id')
            ->paginate(25);

        // What each payer owes on that side right now, so staff see a transfer against its debt.
        $owed = $pending->getCollection()->mapWithKeys(fn (DebtPayment $p) => [
            $p->id => DebtStanding::of($p->user_id, $p->side)->owedPiastres(),
        ]);

        // Appeals against a passenger fee or warning, oldest first (SAFE-11).
        $appeals = MemberWarning::query()
            ->whereNotNull('disputed_at')
            ->whereNull('dispute_resolution')
            ->with('user')
            ->orderBy('disputed_at')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $driverAppeals = FeeAppeal::query()
            ->whereNull('resolution')
            ->with(['user', 'debtEntry'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(50)
            ->get();

        $history = $this->view === 'history'
            ? DebtPayment::query()
                ->whereIn('status', [DebtPaymentStatus::Confirmed->value, DebtPaymentStatus::Rejected->value])
                ->with(['user', 'decidedBy'])
                ->latest('decided_at')
                ->latest('id')
                ->paginate(25, pageName: 'history')
            : null;

        return view('livewire.admin.payments', [
            'history' => $history,
            'pending' => $pending,
            'appeals' => $appeals,
            'driverAppeals' => $driverAppeals,
            'owed' => $owed,
            'mayAct' => $this->admin()?->can(AdminPermission::DebtManage->value) === true,
        ])->title(__('admin.payments.title'));
    }
}
