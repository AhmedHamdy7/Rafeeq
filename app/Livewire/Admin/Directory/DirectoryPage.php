<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * What the directory pages (V, C, Y, B, Q) share: a permission to open, status tabs kept
 * in the address, paging that goes back to page one when the tab changes, and the table
 * tools — saved views, a column picker and CSV export. Each page is read-only — the
 * directory is where staff LOOK things up; decisions about a person stay on Users, about a
 * report on Reports.
 */
abstract class DirectoryPage extends Component
{
    use WithPagination;

    /** An export stops here, so one click cannot pull the whole database into a file. */
    public const int EXPORT_LIMIT = 10_000;

    public const int MAX_VIEWS = 12;

    #[Url(as: 'status')]
    public string $status = 'all';

    public string $viewName = '';

    abstract protected function permission(): AdminPermission;

    /** @return list<string> the tabs after "all", in order */
    abstract protected function statuses(): array;

    /** @return Builder<covariant Model> the rows on screen, filters applied, not paged */
    abstract protected function tableQuery(): Builder;

    /** @return array<string, callable(Model): (string|int|float|null)> CSV heading => value */
    abstract protected function exportColumns(): array;

    public function mount(): void
    {
        abort_unless($this->admin()->can($this->permission()->value), 403);

        if ($this->status !== 'all' && ! in_array($this->status, $this->statuses(), true)) {
            $this->status = 'all';
        }
    }

    public function pick(string $status): void
    {
        $this->status = in_array($status, $this->statuses(), true) ? $status : 'all';
        $this->resetPage();
    }

    /** The key saved views and the column picker are stored under. */
    public static function tableKey(): string
    {
        return Str::kebab(class_basename(static::class));
    }

    /**
     * The filters a saved view keeps. Pages with more than the status tab add theirs.
     *
     * @return array<string, string>
     */
    protected function filters(): array
    {
        return ['status' => $this->status];
    }

    /** @param  array<string, string>  $filters */
    protected function applyFilters(array $filters): void
    {
        $this->pick((string) ($filters['status'] ?? 'all'));
    }

    // ─────────── Saved views: per staff member, per page ───────────

    public function saveView(): void
    {
        $this->validate(['viewName' => ['required', 'string', 'min:1', 'max:40']], attributes: ['viewName' => __('admin/tables.view_name')]);

        $admin = $this->admin();
        $all = (array) ($admin->table_views ?? []);
        $mine = (array) ($all[static::tableKey()] ?? []);
        $name = trim($this->viewName);

        if (! array_key_exists($name, $mine) && count($mine) >= self::MAX_VIEWS) {
            $this->addError('viewName', __('admin/tables.too_many', ['max' => self::MAX_VIEWS]));

            return;
        }

        $mine[$name] = $this->filters();
        $all[static::tableKey()] = $mine;
        $admin->forceFill(['table_views' => $all])->save();

        $this->viewName = '';
    }

    public function applyView(string $name): void
    {
        $filters = $this->views()[$name] ?? null;

        if (is_array($filters)) {
            $this->applyFilters(array_map('strval', $filters));
        }
    }

    public function deleteView(string $name): void
    {
        $admin = $this->admin();
        $all = (array) ($admin->table_views ?? []);
        unset($all[static::tableKey()][$name]);
        $admin->forceFill(['table_views' => $all])->save();
    }

    /** @return array<string, array<string, string>> */
    protected function views(): array
    {
        return (array) (($this->admin()->table_views ?? [])[static::tableKey()] ?? []);
    }

    // ─────────── CSV export: Admins only, written to the audit log ───────────

    public function export(): StreamedResponse
    {
        $admin = $this->admin();
        abort_unless(self::mayExport($admin), 403);

        $columns = $this->exportColumns();
        $query = $this->tableQuery();
        $rows = min((clone $query)->count(), self::EXPORT_LIMIT);

        AdminActionLog::record($admin, 'table.export', $admin, null, [
            'table' => static::tableKey(),
            'filters' => $this->filters(),
            'rows' => $rows,
        ]);

        $filename = static::tableKey().'-'.now('Africa/Cairo')->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($columns, $query): void {
            $out = fopen('php://output', 'w');
            // A byte-order mark, so Excel opens Arabic names as Arabic.
            fwrite($out, "\u{FEFF}");
            fputcsv($out, array_keys($columns));

            $query->limit(self::EXPORT_LIMIT)->get()->each(function (Model $row) use ($out, $columns): void {
                fputcsv($out, array_map(fn (callable $value) => self::cell($value($row)), array_values($columns)));
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function mayExport(?AdminUser $admin): bool
    {
        return $admin?->can(AdminPermission::SettingsManage->value) === true;
    }

    /**
     * 🔒 A cell that starts with = + - @ is a formula to a spreadsheet. Prefixed with a quote,
     * so a name somebody typed into the app cannot run anything on the computer that opens it.
     */
    private static function cell(string|int|float|null $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    protected function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    /**
     * What every directory view gets for the table tools.
     *
     * @return array<string, mixed>
     */
    protected function tableTools(): array
    {
        return [
            'tableKey' => static::tableKey(),
            'savedViews' => $this->views(),
            'mayExport' => self::mayExport($this->admin()),
        ];
    }

    /** The chip colour for a status, the same on every directory page. */
    public static function tone(string $status): string
    {
        return match (true) {
            in_array($status, ['published', 'confirmed', 'active', 'completed', 'matched'], true) => 'ok',
            in_array($status, ['paused', 'pending', 'scheduled', 'preparing', 'en_route', 'in_progress', 'draft'], true) => 'warn',
            str_starts_with($status, 'cancelled') || in_array($status, ['no_show', 'expired', 'exited_due_to_driver_delay', 'ended_early'], true) => 'bad',
            default => 'plain',
        };
    }
}
