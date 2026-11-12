<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\Statistics as Figures;
use App\Domains\Admin\Support\StatisticsPeriod;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * STATISTICS (T01–T05): revenue, trips and seats, growth, and the definitions every number
 * is computed by — for one period, compared with the period before it. Read-only.
 */
#[Layout('components.layouts.admin')]
class Statistics extends Component
{
    public const array TABS = ['revenue', 'trips', 'growth', 'definitions'];

    #[Url(as: 'period')]
    public string $period = 'this_week';

    #[Url(as: 'tab')]
    public string $tab = 'revenue';

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can(AdminPermission::PaymentView->value) === true, 403);

        $this->period = in_array($this->period, StatisticsPeriod::KEYS, true) ? $this->period : 'this_week';
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'revenue';
    }

    public function pickPeriod(string $period): void
    {
        $this->period = in_array($period, StatisticsPeriod::KEYS, true) ? $period : 'this_week';
    }

    public function pickTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'revenue';
    }

    public function render()
    {
        $period = StatisticsPeriod::of($this->period);
        $previous = $period->previous();

        $data = match ($this->tab) {
            'revenue' => [
                'now' => Figures::revenue($period),
                'before' => Figures::revenue($previous),
                'weekly' => Figures::weeklyMoney(),
            ],
            'trips' => [
                'now' => Figures::trips($period),
                'before' => Figures::trips($previous),
                'daily' => Figures::seatsPerDay($period),
            ],
            'growth' => [
                'now' => Figures::growth($period),
                'before' => Figures::growth($previous),
                'weekly' => Figures::weeklySignUps(),
            ],
            default => [],
        };

        return view('livewire.admin.statistics', [
            'current' => $period,
            'previous' => $previous,
            'data' => $data,
        ])->title(__('admin/stats.title'));
    }
}
