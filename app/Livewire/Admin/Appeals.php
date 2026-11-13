<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\SuspensionAppeal;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Trip\Models\MemberWarning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * APPEALS (P): every appeal waiting for a decision, in one list, oldest first.
 *
 * Four kinds live on four pages, because each is decided where its facts are: a driver's
 * fee and a passenger's warning on Payments, a suspension on the member, a report's
 * decision on Reports. This page decides nothing. It is the one place to see what is
 * waiting and how long, and a link to where it is decided.
 *
 * 🔒 Each kind is listed only for staff who may open the page that decides it.
 */
#[Layout('components.layouts.admin')]
class Appeals extends Component
{
    public const array KINDS = ['fee', 'warning', 'suspension', 'reliability', 'report'];

    #[Url(as: 'kind')]
    public string $kind = 'all';

    public function mount(): void
    {
        abort_unless(self::isAvailableTo($this->admin()), 403);

        if ($this->kind !== 'all' && ! in_array($this->kind, $this->visibleKinds(), true)) {
            $this->kind = 'all';
        }
    }

    public static function isAvailableTo(?AdminUser $admin): bool
    {
        return $admin !== null && (
            $admin->can(AdminPermission::PaymentView->value)
            || $admin->can(AdminPermission::MemberView->value)
            || $admin->can(AdminPermission::SafetyView->value)
        );
    }

    public function pick(string $kind): void
    {
        $this->kind = in_array($kind, $this->visibleKinds(), true) ? $kind : 'all';
    }

    /** @return list<string> */
    private function visibleKinds(): array
    {
        $admin = $this->admin();
        $may = fn (AdminPermission $permission): bool => $admin?->can($permission->value) === true;

        return array_values(array_filter([
            $may(AdminPermission::PaymentView) ? 'fee' : null,
            $may(AdminPermission::PaymentView) ? 'warning' : null,
            $may(AdminPermission::MemberView) ? 'suspension' : null,
            $may(AdminPermission::MemberView) ? 'reliability' : null,
            $may(AdminPermission::SafetyView) ? 'report' : null,
        ]));
    }

    /**
     * @return Collection<int, array{kind: string, id: string, who: ?string, since: CarbonImmutable, text: string, url: string}>
     */
    private function rows(array $kinds): Collection
    {
        $rows = collect();
        $limit = 100;

        if (in_array('fee', $kinds, true)) {
            FeeAppeal::query()->whereNull('resolution')->with('user')->oldest()->limit($limit)->get()
                ->each(fn (FeeAppeal $appeal) => $rows->push([
                    'kind' => 'fee', 'id' => $appeal->id, 'who' => $appeal->user?->full_name,
                    'since' => CarbonImmutable::instance($appeal->created_at), 'text' => (string) $appeal->reason,
                    'url' => route('admin.payments'),
                ]));
        }

        if (in_array('warning', $kinds, true)) {
            MemberWarning::query()->whereNotNull('disputed_at')->whereNull('dispute_resolution')->with('user')->orderBy('disputed_at')->limit($limit)->get()
                ->each(fn (MemberWarning $warning) => $rows->push([
                    'kind' => 'warning', 'id' => $warning->id, 'who' => $warning->user?->full_name,
                    'since' => CarbonImmutable::instance($warning->disputed_at), 'text' => (string) $warning->dispute_reason,
                    'url' => route('admin.payments'),
                ]));
        }

        if (in_array('suspension', $kinds, true)) {
            SuspensionAppeal::query()->whereNull('resolved_at')->with('user')->oldest()->limit($limit)->get()
                ->each(fn (SuspensionAppeal $appeal) => $rows->push([
                    'kind' => 'suspension', 'id' => $appeal->id, 'who' => $appeal->user?->full_name,
                    'since' => CarbonImmutable::instance($appeal->created_at), 'text' => (string) $appeal->message,
                    'url' => route('admin.members', ['member' => $appeal->user_id]),
                ]));
        }

        // Reliability appeals: decided on the member's Reliability tab (reverse, or decline).
        if (in_array('reliability', $kinds, true)) {
            ReliabilityEvent::query()->awaitingAppealReview()->with('user')->orderBy('appealed_at')->limit($limit)->get()
                ->each(fn (ReliabilityEvent $event) => $rows->push([
                    'kind' => 'reliability', 'id' => $event->id, 'who' => $event->user?->full_name,
                    'since' => CarbonImmutable::instance($event->appealed_at), 'text' => (string) $event->appeal_reason,
                    'url' => route('admin.members.show', ['user' => $event->user_id, 'tab' => 'reliability']),
                ]));
        }

        if (in_array('report', $kinds, true)) {
            Incident::query()->where('status', IncidentStatus::Appealed->value)->with('reporter')->orderBy('updated_at')->limit($limit)->get()
                ->each(fn (Incident $incident) => $rows->push([
                    'kind' => 'report', 'id' => $incident->id, 'who' => $incident->reporter?->full_name,
                    'since' => CarbonImmutable::instance($incident->updated_at), 'text' => __('admin.safety.categories.'.$incident->category->value),
                    'url' => route('admin.reports'),
                ]));
        }

        return $rows->sortBy(fn (array $row) => $row['since']->getTimestamp())->values();
    }

    private function admin(): ?AdminUser
    {
        return Auth::guard('admin')->user();
    }

    public function render()
    {
        $visible = $this->visibleKinds();
        $all = $this->rows($visible);

        return view('livewire.admin.appeals', [
            'kinds' => $visible,
            'counts' => $all->countBy('kind'),
            'rows' => $this->kind === 'all' ? $all : $all->where('kind', $this->kind)->values(),
        ])->title(__('admin/appeals.title'));
    }
}
