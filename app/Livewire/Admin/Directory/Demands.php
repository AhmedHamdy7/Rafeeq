<?php

namespace App\Livewire\Admin\Directory;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Matching\Enums\CommuteDemandStatus;
use App\Domains\Matching\Models\CommuteDemand;
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

    public function render()
    {
        return view('livewire.admin.directory.demands', [
            'demands' => CommuteDemand::query()
                ->with('passenger')
                ->when($this->status !== 'all', fn ($query) => $query->where('status', $this->status))
                ->latest('created_at')
                ->latest('id')
                ->paginate(25),
            'tabs' => $this->statuses(),
        ])->title(__('admin/directory.demands.title'));
    }
}
