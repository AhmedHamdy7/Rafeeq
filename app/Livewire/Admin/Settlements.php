<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtAccount;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Support\SettlementWeek;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * WEEKLY SETTLEMENTS (W): what each driver owes for a settlement week (Saturday to Friday,
 * Cairo — PAY-11) and where they stand now. Read from the ledger, never from a second
 * bookkeeping: the commission is the sum of the week's commission lines, the payments the
 * sum of its payment lines, and "owes now" is the driver account's running balance.
 *
 * Read-only. Confirming a transfer happens on Payments; a correction is a ledger line.
 */
#[Layout('components.layouts.admin')]
class Settlements extends Component
{
    #[Url(as: 'week')]
    public string $week = '';

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can(AdminPermission::PaymentView->value) === true, 403);

        $this->week = $this->normalise($this->week);
    }

    public function previous(): void
    {
        $this->week = SettlementWeek::shift($this->week, -1);
    }

    public function next(): void
    {
        $next = SettlementWeek::shift($this->week, 1);
        $this->week = $next > SettlementWeek::current() ? SettlementWeek::current() : $next;
    }

    /** Any date in the URL becomes the Saturday that starts its week; nonsense becomes this week. */
    private function normalise(string $week): string
    {
        try {
            return $week === '' ? SettlementWeek::current() : SettlementWeek::weekOf(CarbonImmutable::parse($week, SettlementWeek::TIMEZONE));
        } catch (\Throwable) {
            return SettlementWeek::current();
        }
    }

    public function render()
    {
        $week = $this->normalise($this->week);

        $lines = DebtEntry::query()
            ->toBase()
            ->where('side', DebtSide::Driver->value)
            ->where('week_of', $week)
            ->selectRaw('user_id, type, sum(amount_piastres) as total')
            ->groupBy('user_id', 'type')
            ->get();

        $byDriver = [];
        foreach ($lines as $line) {
            $byDriver[$line->user_id][$line->type] = (int) $line->total;
        }

        $accounts = DebtAccount::query()
            ->where('side', DebtSide::Driver->value)
            ->whereIn('user_id', array_keys($byDriver))
            ->get()
            ->keyBy('user_id');

        $users = User::query()->whereIn('id', array_keys($byDriver))->get(['id', 'full_name'])->keyBy('id');

        $rows = collect($byDriver)->map(function (array $totals, string $userId) use ($accounts, $users) {
            $account = $accounts->get($userId);

            return [
                'id' => $userId,
                'name' => $users->get($userId)?->full_name,
                'commission' => ($totals[DebtEntryType::Commission->value] ?? 0) + ($totals[DebtEntryType::LateFee->value] ?? 0),
                'paid' => -1 * ($totals[DebtEntryType::Payment->value] ?? 0),
                'owesNow' => max(0, (int) ($account?->balance_piastres ?? 0)),
                'blocked' => $account?->suspended_at !== null,
            ];
        })->sortByDesc('owesNow')->values();

        return view('livewire.admin.settlements', [
            'weekStart' => CarbonImmutable::parse($week, SettlementWeek::TIMEZONE),
            'isCurrent' => $week === SettlementWeek::current(),
            'rows' => $rows,
            'totals' => [
                'commission' => $rows->sum('commission'),
                'paid' => $rows->sum('paid'),
                'owedNow' => (int) DebtAccount::query()->where('side', DebtSide::Driver->value)->where('balance_piastres', '>', 0)->sum('balance_piastres'),
                'blocked' => DebtAccount::query()->where('side', DebtSide::Driver->value)->whereNotNull('suspended_at')->count(),
            ],
        ])->title(__('admin/money.settlements.title'));
    }
}
