<?php

namespace App\Domains\Driver\Actions;

use App\Domains\Driver\Enums\VehicleVerificationStatus;
use App\Domains\Driver\Models\VehicleDocument;
use App\Domains\Notification\Enums\NotificationType;
use App\Domains\Notification\Support\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class WarnExpiringRegistrationsAction
{
    public function sweep(): int
    {
        $now = CarbonImmutable::now();
        $days = (int) config('rafeeq.verification.licence_expiry_warning_days');
        $sent = 0;

        $documents = VehicleDocument::query()
            ->whereIn('type', VehicleDocument::REGISTRATION_TYPES)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->where('expires_at', '<=', $now->addDays($days))
            ->where(fn ($query) => $query->whereNull('expiry_warned_for')->orWhereColumn('expiry_warned_for', '!=', 'expires_at'))
            ->whereHas('vehicle', fn ($query) => $query->where('verification_status', VehicleVerificationStatus::Approved->value))
            ->with('vehicle.driverProfile.user')
            ->orderBy('expires_at')
            ->get()
            ->unique('vehicle_id');

        foreach ($documents as $document) {
            VehicleDocument::query()
                ->where('vehicle_id', $document->vehicle_id)
                ->whereIn('type', VehicleDocument::REGISTRATION_TYPES)
                ->whereNotNull('expires_at')
                ->update(['expiry_warned_for' => DB::raw('expires_at')]);

            $driver = $document->vehicle?->driverProfile?->user;

            if ($driver === null) {
                continue;
            }

            Notifier::send($driver, NotificationType::DriverItemExpiring, params: [
                'date' => $document->expires_at->toDateString(),
                'item' => __('notifications.driver_items.vehicle_registration', [], $driver->preferred_language ?: config('app.locale')),
            ], data: [
                'itemKey' => 'vehicle_registration',
                'vehicleId' => (string) $document->vehicle_id,
                'expiresAt' => $document->expires_at->toIso8601String(),
            ]);

            $sent++;
        }

        return $sent;
    }
}
