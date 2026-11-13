<?php

namespace App\Domains\Admin\Support;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\SupportTicketStatus;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Driver\Enums\DriverProfileStatus;
use App\Domains\Driver\Models\DriverProfile;
use App\Domains\Payment\Enums\DebtPaymentStatus;
use App\Domains\Payment\Models\DebtPayment;
use App\Domains\Safety\Enums\IncidentStatus;
use App\Domains\Safety\Models\Incident;
use App\Domains\Safety\Models\SosEvent;
use Illuminate\Support\Facades\Route;

/**
 * TEAM ALERTS (N03): the bell in the top bar. Not a feed that has to be read and cleared —
 * a live count of what is waiting past its promise right now, so it can never say "3 new"
 * about things somebody already dealt with. Each line is shown only to staff who may open
 * the page that deals with it, and links there.
 */
final class TeamAlerts
{
    /** How long an item may wait before the bell rings for it. */
    public const int LATE_HOURS = 24;

    /**
     * @return list<array{key: string, count: int, route: string, params: array<string, string>, tone: string}>
     */
    public static function for(?AdminUser $admin): array
    {
        if ($admin === null) {
            return [];
        }

        $may = fn (AdminPermission $permission): bool => $admin->can($permission->value);
        $late = now()->subHours(self::LATE_HOURS);

        $lines = [
            $may(AdminPermission::SafetyView) ? ['key' => 'sos_untouched', 'tone' => 'bad', 'route' => 'admin.safety', 'params' => [],
                'count' => SosEvent::query()->whereNull('cancelled_at')->whereNull('resolution')->whereNull('first_touch_at')->count()] : null,
            $may(AdminPermission::SafetyView) ? ['key' => 'reports_overdue', 'tone' => 'bad', 'route' => 'admin.reports', 'params' => [],
                'count' => Incident::query()->whereIn('status', IncidentStatus::openValues())->where('sla_due_at', '<', now())->count()] : null,
            $may(AdminPermission::PaymentView) ? ['key' => 'payments_late', 'tone' => 'warn', 'route' => 'admin.payments', 'params' => [],
                'count' => DebtPayment::query()->where('status', DebtPaymentStatus::PendingConfirmation->value)->where('created_at', '<', $late)->count()] : null,
            $may(AdminPermission::DriverView) ? ['key' => 'drivers_late', 'tone' => 'warn', 'route' => 'admin.drivers', 'params' => [],
                'count' => DriverProfile::query()->where('status', DriverProfileStatus::PendingReview->value)->whereHas('user')->where('updated_at', '<', $late)->count()] : null,
            $may(AdminPermission::TicketView) ? ['key' => 'escalations', 'tone' => 'warn', 'route' => 'admin.support', 'params' => [],
                'count' => SupportTicket::query()->where('category', 'escalation')->whereIn('status', [SupportTicketStatus::Open->value, SupportTicketStatus::InProgress->value])->count()] : null,
        ];

        return array_values(array_filter($lines, fn (?array $line) => $line !== null && $line['count'] > 0 && Route::has($line['route'])));
    }
}
