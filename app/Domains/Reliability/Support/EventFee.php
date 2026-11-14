<?php

namespace App\Domains\Reliability\Support;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\DebtEntry;
use App\Domains\Payment\Models\FeeAppeal;
use App\Domains\Payment\Support\DebtLedger;
use App\Domains\Payment\Support\DriverFees;
use App\Domains\Reliability\Enums\ReliabilityEventKind;
use App\Domains\Reliability\Models\ReliabilityEvent;
use App\Domains\Trip\Enums\DisputeResolution;

final class EventFee
{
    public static function feeKinds(ReliabilityEventKind $kind): array
    {
        return match ($kind) {
            ReliabilityEventKind::DriverCancelledBooking,
            ReliabilityEventKind::DriverDisplacedPassenger,
            ReliabilityEventKind::DriverCancelledDay => [DriverFees::CANCEL],
            ReliabilityEventKind::DriverAbandonedActiveTrip => [DriverFees::ACTIVE_CANCEL],
            ReliabilityEventKind::DriverLateCausedExit => [DriverFees::LATE],
            ReliabilityEventKind::DriverLate => [DriverFees::NO_SHOW, DriverFees::LATE],
            default => [],
        };
    }

    public static function entryFor(ReliabilityEvent $event): ?DebtEntry
    {
        $kinds = self::feeKinds($event->kind);

        if ($kinds === [] || $event->booking_id === null) {
            return null;
        }

        $entries = DebtEntry::query()
            ->where('user_id', $event->user_id)
            ->where('side', DebtSide::Driver->value)
            ->where('type', DebtEntryType::Fee->value)
            ->whereIn('idempotency_key', array_map(fn (string $kind): string => "{$kind}:{$event->booking_id}", $kinds))
            ->get();

        foreach ($kinds as $kind) {
            $entry = $entries->firstWhere('idempotency_key', "{$kind}:{$event->booking_id}");

            if ($entry !== null && ! self::isVoided($entry)) {
                return $entry;
            }
        }

        return null;
    }

    public static function forEvents(iterable $events): array
    {
        $keys = [];

        foreach ($events as $event) {
            if ($event->booking_id === null) {
                continue;
            }

            foreach (self::feeKinds($event->kind) as $kind) {
                $keys[(string) $event->getKey()][] = "{$kind}:{$event->booking_id}";
            }
        }

        if ($keys === []) {
            return [];
        }

        $all = array_merge(...array_values($keys));

        $entries = DebtEntry::query()
            ->where('side', DebtSide::Driver->value)
            ->where('type', DebtEntryType::Fee->value)
            ->whereIn('idempotency_key', $all)
            ->get()
            ->keyBy('idempotency_key');

        if ($entries->isEmpty()) {
            return [];
        }

        $voided = DebtEntry::query()
            ->whereIn('idempotency_key', array_map(fn (string $key): string => "void:{$key}", $all))
            ->pluck('idempotency_key')
            ->flip();

        $ids = $entries->pluck('id');

        $credited = DebtEntry::query()
            ->whereIn('idempotency_key', $ids->map(fn (string $id): string => "reliability_appeal_reversal:{$id}")->all())
            ->pluck('idempotency_key')
            ->flip();

        $appeals = FeeAppeal::query()->whereIn('debt_entry_id', $ids->all())->get()->keyBy('debt_entry_id');

        $fees = [];

        foreach ($events as $event) {
            foreach ($keys[(string) $event->getKey()] ?? [] as $key) {
                $entry = $entries->get($key);

                if ($entry === null || $entry->user_id !== $event->user_id || $voided->has("void:{$key}")) {
                    continue;
                }

                $fees[(string) $event->getKey()] = [
                    'debtEntryId' => (string) $entry->id,
                    'amountPiastres' => (int) $entry->amount_piastres,
                    'reversed' => $credited->has("reliability_appeal_reversal:{$entry->id}")
                        || $appeals->get($entry->id)?->resolution === DisputeResolution::Overturned,
                ];

                break;
            }
        }

        return $fees;
    }

    public static function eventFor(DebtEntry $entry): ?ReliabilityEvent
    {
        if ($entry->booking_id === null) {
            return null;
        }

        $kinds = array_filter(
            ReliabilityEventKind::cases(),
            fn (ReliabilityEventKind $kind): bool => in_array((string) $entry->reason_code, self::feeKinds($kind), true),
        );

        return ReliabilityEvent::query()
            ->where('user_id', $entry->user_id)
            ->where('booking_id', $entry->booking_id)
            ->whereIn('kind', array_map(fn (ReliabilityEventKind $kind): string => $kind->value, $kinds))
            ->first();
    }

    public static function isReversed(DebtEntry $entry): bool
    {
        return DebtEntry::query()->where('idempotency_key', self::reversalKey($entry))->exists()
            || FeeAppeal::query()
                ->where('debt_entry_id', $entry->id)
                ->where('resolution', DisputeResolution::Overturned->value)
                ->exists();
    }

    public static function reverse(ReliabilityEvent $event, AdminUser $admin, string $note): void
    {
        $entry = self::entryFor($event);

        if ($entry === null || self::isReversed($entry)) {
            return;
        }

        $driver = User::query()->find($event->user_id);

        if ($driver === null) {
            return;
        }

        DebtLedger::credit(
            $driver,
            DebtEntryType::Credit,
            (int) $entry->amount_piastres,
            'appeal_upheld',
            meta: ['rule' => 'PAY-10', 'feeEntryId' => $entry->id, 'reliabilityEventId' => (string) $event->getKey()],
            side: DebtSide::Driver,
            idempotencyKey: self::reversalKey($entry),
        );

        FeeAppeal::query()
            ->where('debt_entry_id', $entry->id)
            ->whereNull('resolution')
            ->get()
            ->each(fn (FeeAppeal $appeal) => $appeal->forceFill([
                'resolution' => DisputeResolution::Overturned,
                'resolved_by_admin_id' => $admin->getKey(),
                'resolved_at' => now(),
                'resolution_note' => mb_substr($note, 0, 255),
            ])->save());
    }

    private static function reversalKey(DebtEntry $entry): string
    {
        return "reliability_appeal_reversal:{$entry->id}";
    }

    private static function isVoided(DebtEntry $entry): bool
    {
        return DebtEntry::query()->where('idempotency_key', "void:{$entry->idempotency_key}")->exists();
    }
}
