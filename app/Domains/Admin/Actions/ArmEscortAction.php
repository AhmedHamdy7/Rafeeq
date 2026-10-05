<?php

namespace App\Domains\Admin\Actions;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Geo\Models\Corridor;
use App\Domains\Safety\Models\EscortWindow;
use App\Domains\Safety\Support\EscortCoverage;
use App\Domains\Safety\Support\SafetySettings;
use App\Domains\Shared\Exceptions\DomainException;
use App\Domains\Shared\Support\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Night escort mode (Master Plan: "auto-arms 9 PM–5 AM, with monitoring from the operations
 * team"; Chapter 12 §Safety).
 *
 * What arming means, concretely: while a window covers a corridor, every run that starts on it is
 * counted against the window and flagged on the live board, so the desk watches those cars before
 * anything has gone wrong rather than after. The member's app is unchanged — escort is the desk
 * paying closer attention, not a feature somebody has to switch on in the car.
 *
 * Three ways in:
 *
 * - **Every night, automatically**, when `safety.night_escort_enabled` is on. No admin and so no
 *   audit row: the window itself (`is_auto`, no `armed_by`) is the record, and the setting that
 *   caused it is audited where it was changed.
 * - **By hand**, for a corridor the desk is worried about outside the night window — an incident
 *   last week, a road closed tonight. With a reason, because "why was this armed" is asked later.
 * - **Stood down by hand**, early. Also with a reason: ending watch over a route is the decision
 *   somebody will be asked about if that is the night something happens on it.
 *
 * 🔴 Deliberately NOT written to `safety_events`. That table is the timeline of things that
 * happened to members; a corridor being watched did not happen to anybody (Screen Map §8.0.1).
 */
final readonly class ArmEscortAction
{
    public const int MAX_MANUAL_HOURS = 12;

    /**
     * Tonight's window for every corridor that does not already have one. Idempotent: run twice,
     * arms once — the scheduler can retry, and a deploy can run it by hand, without doubling up.
     *
     * @return int how many corridors were armed by this call
     */
    public function armTonight(): int
    {
        if (! SafetySettings::nightEscortEnabled()) {
            return 0;
        }

        [$start, $end] = EscortCoverage::tonight();

        $armed = 0;

        Corridor::query()->select('id')->orderBy('id')->each(function (Corridor $corridor) use ($start, $end, &$armed): void {
            DB::transaction(function () use ($corridor, $start, $end, &$armed): void {
                // The corridor row is the lock: two runs of the command must not both see "none yet".
                Corridor::query()->whereKey($corridor->id)->lockForUpdate()->first();

                $covered = EscortWindow::query()
                    ->where('corridor_id', $corridor->id)
                    ->where('starts_at', '<', $end)
                    ->where('ends_at', '>', $start)
                    ->exists();

                if ($covered) {
                    return;
                }

                $window = new EscortWindow([
                    'corridor_id' => $corridor->id,
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'is_auto' => true,
                ]);
                $window->save();

                $armed++;
            });
        });

        return $armed;
    }

    public function arm(Corridor $corridor, AdminUser $admin, int $hours, string $reason): EscortWindow
    {
        if ($hours < 1 || $hours > self::MAX_MANUAL_HOURS) {
            throw DomainException::of(ErrorCode::ValidationFailed, fields: [
                'hours' => [__('validation.between.numeric', ['attribute' => 'hours', 'min' => 1, 'max' => self::MAX_MANUAL_HOURS])],
            ]);
        }

        return DB::transaction(function () use ($corridor, $admin, $hours, $reason): EscortWindow {
            Corridor::query()->whereKey($corridor->id)->lockForUpdate()->firstOrFail();

            if (EscortCoverage::activeFor($corridor->id) !== null) {
                throw DomainException::of(ErrorCode::EscortAlreadyArmed);
            }

            $window = new EscortWindow([
                'corridor_id' => $corridor->id,
                'starts_at' => now(),
                'ends_at' => now()->addHours($hours),
                'is_auto' => false,
            ]);
            // Not fillable: who armed it is the session's admin, never a form field.
            $window->armed_by = $admin->id;
            $window->save();

            AdminActionLog::record($admin, 'escort.arm', $window,
                after: [
                    'corridor_id' => $corridor->id,
                    'ends_at' => $window->ends_at->toIso8601String(),
                ],
                reason: $reason,
            );

            return $window;
        });
    }

    /**
     * Ends the watch now. The row is kept with its new end time — how long a corridor was really
     * covered is part of what a review of that night needs.
     */
    public function disarm(EscortWindow $window, AdminUser $admin, string $reason): EscortWindow
    {
        return DB::transaction(function () use ($window, $admin, $reason): EscortWindow {
            $window = EscortWindow::query()->whereKey($window->getKey())->lockForUpdate()->firstOrFail();

            if (! $window->isActive()) {
                throw DomainException::of(ErrorCode::EscortNotActive);
            }

            $before = ['ends_at' => $window->ends_at->toIso8601String()];

            $window->ends_at = now();
            $window->save();

            AdminActionLog::record($admin, 'escort.disarm', $window,
                before: $before,
                after: ['ends_at' => $window->ends_at->toIso8601String()],
                reason: $reason,
            );

            return $window;
        });
    }
}
