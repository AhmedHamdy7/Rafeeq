<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * What the four directory pages (C, Y, B, Q) share: a permission to open, status tabs kept
 * in the address, and paging that goes back to page one when the tab changes. Each page
 * is read-only — the directory is where staff LOOK things up; decisions about a person
 * stay on Users, about a report on Reports.
 */
abstract class DirectoryPage extends Component
{
    use WithPagination;

    #[Url(as: 'status')]
    public string $status = 'all';

    abstract protected function permission(): AdminPermission;

    /** @return list<string> the tabs after "all", in order */
    abstract protected function statuses(): array;

    public function mount(): void
    {
        abort_unless(Auth::guard('admin')->user()?->can($this->permission()->value) === true, 403);

        if ($this->status !== 'all' && ! in_array($this->status, $this->statuses(), true)) {
            $this->status = 'all';
        }
    }

    public function pick(string $status): void
    {
        $this->status = in_array($status, $this->statuses(), true) ? $status : 'all';
        $this->resetPage();
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
