<?php

namespace App\Livewire\Admin\Concerns;

use App\Domains\Admin\Enums\AdminPermission;
use App\Domains\Admin\Support\AdminActionLog;
use App\Domains\Driver\Models\DriverProfile;
use Illuminate\Support\Facades\Auth;

trait ShowsMemberDetails
{
    public ?string $revealedId = null;

    public function revealNumbers(string $userId): void
    {
        $admin = Auth::guard('admin')->user();

        abort_unless($admin?->can(AdminPermission::VerificationViewDocument->value) === true, 403);

        $profile = DriverProfile::query()->whereKey($userId)->firstOrFail();

        AdminActionLog::record($admin, 'driver.view_identity_numbers', $profile);

        $this->revealedId = $userId;
    }

    public static function detailRelations(string $prefix = ''): array
    {
        return [
            $prefix.'organization',
            $prefix.'stats',
            $prefix.'verifications' => fn ($query) => $query->with('documents', 'reviewer')->orderBy('type'),
            $prefix.'driverProfile.reviewer',
            $prefix.'driverProfile.vehicles.documents',
        ];
    }

    private function maySeeDocuments(): bool
    {
        return Auth::guard('admin')->user()?->can(AdminPermission::VerificationViewDocument->value) === true;
    }
}
