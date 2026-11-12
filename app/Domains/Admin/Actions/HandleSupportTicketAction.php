<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Enums\SupportTicketStatus;
use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Models\SupportTicket;
use App\Domains\Admin\Support\AdminActionLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Support cases (X): take one, resolve it, close it. Each step is one audit row with who,
 * when, the status before and after, and — for resolve and close — the note saying what
 * was done, so the next person to open the case does not start from nothing.
 *
 * The row is locked while it changes: two agents pressing "take" at once leave the case
 * with exactly one of them, and the audit log says which.
 */
final class HandleSupportTicketAction
{
    public function take(SupportTicket $ticket, AdminUser $admin): SupportTicket
    {
        return $this->move($ticket, $admin, 'ticket.take', function (SupportTicket $locked) use ($admin): void {
            if (in_array($locked->status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true)) {
                throw ValidationException::withMessages(['ticket' => __('admin/support.already_done')]);
            }

            $locked->forceFill(['assigned_admin_id' => $admin->id, 'status' => SupportTicketStatus::InProgress])->save();
        });
    }

    public function finish(SupportTicket $ticket, AdminUser $admin, SupportTicketStatus $to, string $note): SupportTicket
    {
        if (! in_array($to, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true)) {
            throw ValidationException::withMessages(['ticket' => __('admin/support.already_done')]);
        }

        return $this->move($ticket, $admin, $to === SupportTicketStatus::Resolved ? 'ticket.resolve' : 'ticket.close', function (SupportTicket $locked) use ($admin, $to): void {
            if (in_array($locked->status, [SupportTicketStatus::Resolved, SupportTicketStatus::Closed], true)) {
                throw ValidationException::withMessages(['ticket' => __('admin/support.already_done')]);
            }

            $locked->forceFill(['status' => $to, 'assigned_admin_id' => $locked->assigned_admin_id ?? $admin->id])->save();
        }, $note);
    }

    private function move(SupportTicket $ticket, AdminUser $admin, string $action, callable $change, ?string $note = null): SupportTicket
    {
        if (! $admin->can(AdminPermission::TicketResolve->value)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($ticket, $admin, $action, $change, $note) {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $before = ['status' => $locked->status->value, 'assigned_admin_id' => $locked->assigned_admin_id];

            $change($locked);

            AdminActionLog::record(
                $admin,
                $action,
                $locked,
                $before,
                ['status' => $locked->status->value, 'assigned_admin_id' => $locked->assigned_admin_id],
                $note,
            );

            return $locked;
        });
    }
}
