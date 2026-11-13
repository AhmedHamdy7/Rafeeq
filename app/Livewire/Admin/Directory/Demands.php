<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;

/** DEMANDS (Q): what passengers asked for and nobody offers yet — where the next commutes should be. */
#[Layout('components.layouts.admin')]
class Demands extends DirectoryPage
{
    protected function permission(): AdminPermission
    {
        return AdminPermission::CommuteView;
    }

    protected function statuses(): array
    {
        return array_map(fn (CommuteDemandStatus $status) => $status->value, CommuteDemandStatus::cases());
    }

    protected function tableQuery(): Builder
    {
        return CommuteDemand::query()
            ->with('passenger')
            ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
            ->latest('created_at')
            ->latest('id');
    }

    protected function exportColumns(): array
    {
        return [
            'id' => fn (CommuteDemand $demand) => $demand->id,
            'passenger' => fn (CommuteDemand $demand) => $demand->passenger?->full_name,
            'status' => fn (CommuteDemand $demand) => $demand->status->value,
            'created_at' => fn (CommuteDemand $demand) => $demand->created_at?->timezone('Africa/Cairo')->format('Y-m-d H:i'),
        ];
    }

    public function render()
    {
        return view('livewire.admin.directory.demands', [
            'demands' => $this->tableQuery()->paginate(25),
            'tabs' => $this->statuses(),
            ...$this->tableTools(),
        ])->title(__('admin/directory.demands.title'));
    }
}
