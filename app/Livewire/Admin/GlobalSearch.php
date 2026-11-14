<?php

namespace App\Livewire\Admin;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Admin\Support\Navigation;
use App\Domains\Booking\Models\Booking;
use App\Domains\Commute\Models\CommuteOffer;
use App\Domains\Commute\Models\ScheduledTrip;
use App\Domains\Driver\Models\Vehicle;
use App\Domains\Identity\Models\User;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SosEvent;
use App\Domains\Shared\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;

/**
 * THE TOP-BAR SEARCH: one box for a person (name or full phone number), a car (plate), any
 * reference code staff read off a screen (booking, report, SOS, support case, commute, trip
 * day — the last characters shown in the grey code chip), and the dashboard's own pages.
 * Results appear as you type, grouped, each linking to its page.
 *
 * 🔒 Each group only for staff who may open what it links to. A phone number finds an
 * account only when typed in full, and is shown masked — the same rule as the Users page.
 */
class GlobalSearch extends Component
{
    public string $q = '';

    /** How many results each group shows. */
    private const int PER_GROUP = 5;

    /**
     * @return list<array{group: string, items: list<array{label: string, meta: ?string, url: string}>}>
     */
    private function results(): array
    {
        $q = trim($this->q);
        $admin = Auth::guard('admin')->user();

        if (mb_strlen($q) < 2 || ! $admin instanceof AdminUser) {
            return [];
        }

        $may = fn (AdminPermission $permission): bool => $admin->can($permission->value);
        $like = '%'.addcslashes($q, '%_\\').'%';
        $groups = [];

        if ($may(AdminPermission::MemberView)) {
            $members = User::query();

            try {
                $members->where('phone_e164', PhoneNumber::fromRaw($q)->e164);
            } catch (InvalidArgumentException) {
                $members->where('full_name', 'like', $like);
            }

            $groups['members'] = $members->limit(self::PER_GROUP)->get(['id', 'full_name', 'phone_e164'])
                ->map(fn (User $user) => [
                    'label' => (string) $user->full_name,
                    'meta' => self::masked($user->phone_e164),
                    'url' => route('admin.members.show', $user->id),
                ])->all();
        }

        if ($may(AdminPermission::DriverView) && mb_strlen($q) >= 3) {
            $groups['vehicles'] = Vehicle::query()
                ->where('plate_normalized', 'like', '%'.addcslashes(Vehicle::normalizePlate($q), '%_\\').'%')
                ->with('driverProfile.user')
                ->limit(self::PER_GROUP)
                ->get()
                ->map(fn (Vehicle $vehicle) => [
                    'label' => (string) $vehicle->plate_number,
                    'meta' => trim($vehicle->make.' '.$vehicle->model.' · '.($vehicle->driverProfile?->user?->full_name ?? '')),
                    'url' => route('admin.vehicles.show', $vehicle->id),
                ])->all();
        }

        // A reference code: letters and digits, with at least one digit, as the grey code chips show.
        if (preg_match('/^[0-9a-z]{4,26}$/i', $q) === 1 && preg_match('/\d/', $q) === 1) {
            $code = Str::upper($q);
            $refs = [];

            $find = function (string $model, AdminPermission $permission, string $route, string $kind) use (&$refs, $may, $code): void {
                if (! $may($permission)) {
                    return;
                }

                $model::query()->where('id', 'like', '%'.$code)->limit(3)->pluck('id')
                    ->each(function (string $id) use (&$refs, $route, $kind): void {
                        $refs[] = [
                            'label' => Str::upper(Str::substr($id, -6)),
                            'meta' => __('admin/shell.search.kinds.'.$kind),
                            'url' => route($route, $id),
                        ];
                    });
            };

            $find(Booking::class, AdminPermission::CommuteView, 'admin.bookings.show', 'booking');
            $find(Incident::class, AdminPermission::SafetyView, 'admin.reports.show', 'report');
            $find(SosEvent::class, AdminPermission::SafetyView, 'admin.safety.sos', 'sos');
            $find(SupportTicket::class, AdminPermission::TicketView, 'admin.support.show', 'support');
            $find(CommuteOffer::class, AdminPermission::CommuteView, 'admin.commutes.show', 'commute');
            $find(ScheduledTrip::class, AdminPermission::TripView, 'admin.trip-days.show', 'trip_day');

            $groups['references'] = array_slice($refs, 0, self::PER_GROUP * 2);
        }

        // The dashboard's own pages, so "settlements" or "تسويات" jumps straight there.
        $needle = mb_strtolower($q);
        $pages = [];
        foreach (Navigation::for($admin) as $group) {
            foreach ($group['items'] as $item) {
                $label = (string) __('admin/shell.nav.'.$item['key']);

                if (str_contains(mb_strtolower($label), $needle)) {
                    $pages[] = ['label' => $label, 'meta' => (string) __('admin/shell.groups.'.$group['key']), 'url' => route($item['route'])];
                }
            }
        }
        $groups['pages'] = array_slice($pages, 0, self::PER_GROUP);

        $out = [];
        foreach ($groups as $name => $items) {
            if ($items !== []) {
                $out[] = ['group' => $name, 'items' => $items];
            }
        }

        return $out;
    }

    private static function masked(?string $phone): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        try {
            return PhoneNumber::fromRaw($phone)->masked();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function render()
    {
        return view('livewire.admin.global-search', [
            'groups' => $this->results(),
            // "Search Users for …" only for staff who may open Users.
            'membersUrl' => Auth::guard('admin')->user()?->can(AdminPermission::MemberView->value) === true
                ? route('admin.members', ['q' => trim($this->q)])
                : null,
        ]);
    }
}
