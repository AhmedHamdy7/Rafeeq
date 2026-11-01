<?php

namespace Database\Factories;

use App\Domains\Admin\Models\AdminUser;
use App\Domains\Identity\Models\User;
use App\Domains\Payment\Enums\DebtEntryType;
use App\Domains\Payment\Enums\DebtSide;
use App\Domains\Payment\Models\Coupon;
use App\Domains\Payment\Models\DebtEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Coupon>
 */
class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'side' => DebtSide::Passenger,
            'value_piastres' => 1_500,
            'source_entry_id' => DebtEntry::factory()->state(['type' => DebtEntryType::Fee, 'reason_code' => 'driver_no_show']),
            'debt_entry_id' => DebtEntry::factory()->state([
                'side' => DebtSide::Passenger,
                'type' => DebtEntryType::Coupon,
                'amount_piastres' => -1_500,
                'balance_after_piastres' => -1_500,
                'reason_code' => 'coupon',
            ]),
            'issued_by_admin_id' => AdminUser::factory(),
            'note' => 'Driver no-show on a confirmed seat; half the fee he paid.',
        ];
    }
}
