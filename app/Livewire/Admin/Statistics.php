<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Admin\Support\Statistics as Figures;
use App\Domains\Admin\Support\StatisticsFilter;
use App\Domains\Admin\Support\StatisticsInsights;
use App\Domains\Admin\Support\StatisticsPeriod;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * STATISTICS (T01–T05): revenue, trips and seats, growth, supply and demand, quality and
 * safety, team performance, money health, and the definitions every number is computed by —
 * for one period, compared with the period before it. Read-only. Area and car tier narrow the
 * figures that come from commutes; Admins can export a tab as CSV (written to the audit log)
 * or print it to PDF.
 */
#[Layout('components.layouts.admin')]
class Statistics extends Component
{
    public const array TABS = ['revenue', 'trips', 'growth', 'supply', 'quality', 'team', 'money', 'definitions'];

    /** The tabs the area and car-tier filters change. */
    public const array FILTERED_TABS = ['trips', 'supply', 'quality'];

    #[Url(as: 'period')]
    public string $period = 'this_week';

    #[Url(as: 'tab')]
    public string $tab = 'revenue';

    #[Url(as: 'area')]
    public string $area = '';

    #[Url(as: 'tier')]
    public string $tier = '';

    #[Url(as: 'women')]
    public bool $womenOnly = false;

    /** The custom range (Cairo days, inclusive), used when the period is `custom`. */
    #[Url(as: 'from')]
    public string $from = '';

    #[Url(as: 'to')]
    public string $to = '';

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can(AdminPermission::PaymentView->value) === true, 403);

        $this->period = in_array($this->period, [...StatisticsPeriod::KEYS, 'custom'], true) ? $this->period : 'this_week';
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'revenue';
        $filter = $this->currentFilter();
        $this->area = $filter->area;
        $this->tier = $filter->tier;
    }

    public function pickPeriod(string $period): void
    {
        $this->period = in_array($period, [...StatisticsPeriod::KEYS, 'custom'], true) ? $period : 'this_week';

        if ($this->period === 'custom' && $this->from === '') {
            // A sensible start: the last 30 days, which staff then adjust.
            $this->from = now('Africa/Cairo')->subDays(29)->toDateString();
            $this->to = now('Africa/Cairo')->toDateString();
        }
    }

    public function pickTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'revenue';
    }

    /** The period on screen: a named one, or the custom range (this week if the range is not valid). */
    private function currentPeriod(): StatisticsPeriod
    {
        return $this->period === 'custom'
            ? (StatisticsPeriod::custom($this->from, $this->to) ?? StatisticsPeriod::of('this_week'))
            : StatisticsPeriod::of($this->period);
    }

    private function currentFilter(): StatisticsFilter
    {
        return StatisticsFilter::of($this->area, $this->tier, $this->womenOnly);
    }

    /** @return array<string, mixed> */
    private function figures(StatisticsPeriod $period, StatisticsPeriod $previous): array
    {
        $filter = $this->currentFilter();

        return match ($this->tab) {
            'revenue' => [
                'now' => Figures::revenue($period),
                'before' => Figures::revenue($previous),
                'weekly' => Figures::weeklyMoney(),
                'feesByReason' => Figures::feesByReason($period),
                'settingChanges' => Figures::moneySettingChanges(),
            ],
            'trips' => [
                'now' => Figures::trips($period, $filter),
                'before' => Figures::trips($previous, $filter),
                'daily' => Figures::seatsPerDay($period, $filter),
            ],
            'growth' => [
                'now' => Figures::growth($period),
                'before' => Figures::growth($previous),
                'weekly' => Figures::weeklySignUps(),
            ],
            'supply' => StatisticsInsights::supply($period, $filter),
            'quality' => [
                'now' => StatisticsInsights::quality($period, $filter),
                'before' => StatisticsInsights::quality($previous, $filter),
            ],
            'team' => StatisticsInsights::team($period),
            'money' => StatisticsInsights::money(),
            default => [],
        };
    }

    /**
     * The open tab as CSV: one row per figure (or per table row), Admins only, audited.
     */
    public function export(): StreamedResponse
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser && $admin->can(AdminPermission::SettingsManage->value), 403);
        abort_if($this->tab === 'definitions', 404);

        $period = $this->currentPeriod();
        $rows = self::flatten($this->figures($period, $period->previous()));

        AdminActionLog::record($admin, 'table.export', $admin, null, [
            'table' => 'statistics.'.$this->tab,
            'filters' => ['period' => $this->period, 'from' => $this->from, 'to' => $this->to, 'area' => $this->area, 'tier' => $this->tier, 'womenOnly' => $this->womenOnly],
            'rows' => count($rows),
        ]);

        $filename = 'statistics-'.$this->tab.'-'.$period->firstDay().'.csv';

        return response()->streamDownload(function () use ($rows, $period): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['figure', 'value', 'period']);
            foreach ($rows as $key => $value) {
                $value = (string) $value;
                fputcsv($out, [$key, $value !== '' && in_array($value[0], ['=', '+', '@'], true) ? "'".$value : $value, $period->firstDay().'..'.$period->lastDay()]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * `now.commission` => 842000, `pairs.0.pair` => "Nasr City → Smart Village", …
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, scalar|null>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $out += self::flatten($value, $name);
            } elseif (is_scalar($value) || $value === null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    public function render()
    {
        $period = $this->currentPeriod();
        $previous = $period->previous();
        $admin = Auth::guard('admin')->user();

        return view('livewire.admin.statistics', [
            'current' => $period,
            'previous' => $previous,
            'data' => $this->figures($period, $previous),
            'areas' => StatisticsFilter::areas(),
            'filtered' => in_array($this->tab, self::FILTERED_TABS, true),
            'customInvalid' => $this->period === 'custom' && StatisticsPeriod::custom($this->from, $this->to) === null,
            'mayExport' => $admin?->can(AdminPermission::SettingsManage->value) === true,
        ])->title(__('admin/stats.title'));
    }
}
