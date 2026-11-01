<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The driver-only fee ledger moves into the debt ledger, and the old pair of tables goes
 * (PAY-08, decision D-0005; Feature 03 backend file L-2).
 *
 * Every `driver_fee_ledger` row is copied, oldest first, as a driver-side line of the same person:
 *
 * | old type      | new type     |
 * |---------------|--------------|
 * | `fee_due`     | `commission` |
 * | `fee_settled` | `payment`    |
 * | `adjustment`  | `adjustment` |
 * | `write_off`   | `write_off`  |
 *
 * The running balance is recomputed rather than copied, because the old writer clamped at zero
 * and the new ledger does not (a credit is a real, negative balance now). The week each line
 * belongs to is the Cairo Saturday→Friday week of when it was written (PAY-11).
 *
 * `driver_balances` is not copied: everything in it was either a projection of the ledger (the
 * debt, rebuilt here into `debt_accounts`), the 200 EGP cap's flag (gone with the cap, PAY-15),
 * or lifetime earnings — which the debt-only ledger deliberately does not hold, and which the
 * bookings themselves still answer.
 *
 * In the beta's real data only `fee_due` was ever written, so in practice this is "every seat's
 * commission so far, as commission".
 */
return new class extends Migration
{
    private const array TYPES = [
        'fee_due' => 'commission',
        'fee_settled' => 'payment',
        'adjustment' => 'adjustment',
        'write_off' => 'write_off',
    ];

    public function up(): void
    {
        if (Schema::hasTable('driver_fee_ledger')) {
            $balances = [];

            DB::table('driver_fee_ledger')
                ->orderBy('created_at')
                ->orderBy('id')
                ->each(function (object $row) use (&$balances): void {
                    $userId = $row->driver_profile_id;
                    $balances[$userId] = ($balances[$userId] ?? 0) + (int) $row->amount_piastres;
                    $type = self::TYPES[$row->type] ?? 'adjustment';

                    DB::table('debt_entries')->insert([
                        'id' => (string) Str::ulid(),
                        'user_id' => $userId,
                        'side' => 'driver',
                        'type' => $type,
                        'amount_piastres' => (int) $row->amount_piastres,
                        'balance_after_piastres' => $balances[$userId],
                        'reason_code' => $type === 'commission' ? 'seat_commission' : 'migrated_'.$row->type,
                        'week_of' => CarbonImmutable::parse($row->created_at, 'UTC')
                            ->setTimezone('Africa/Cairo')
                            ->startOfWeek(CarbonImmutable::SATURDAY)
                            ->toDateString(),
                        'booking_id' => $row->booking_id,
                        // One commission per booking, the same key the new writer uses.
                        'idempotency_key' => $type === 'commission' && $row->booking_id !== null
                            ? 'commission:'.$row->booking_id
                            : null,
                        'meta' => json_encode(['migrated_from' => 'driver_fee_ledger', 'old_id' => $row->id, 'note' => $row->note]),
                        'created_at' => $row->created_at,
                    ]);
                });

            foreach ($balances as $userId => $balance) {
                DB::table('debt_accounts')->insert([
                    'id' => (string) Str::ulid(),
                    'user_id' => $userId,
                    'side' => 'driver',
                    'balance_piastres' => $balance,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::dropIfExists('driver_balances');
        Schema::dropIfExists('driver_fee_ledger');
    }

    /**
     * The old tables come back EMPTY. Copying the lines back would have to invent a clamp the new
     * ledger does not have; a rollback is for a broken deploy, and the debt ledger keeps the truth.
     */
    public function down(): void
    {
        Schema::create('driver_fee_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('driver_profile_id')->constrained('driver_profiles', 'user_id');
            $table->foreignUlid('booking_id')->nullable()->constrained('bookings')->nullOnDelete();
            $table->string('type', 20);
            $table->integer('amount_piastres');
            $table->integer('balance_after_piastres');
            $table->foreignUlid('settled_from_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['driver_profile_id', 'created_at']);
        });

        Schema::create('driver_balances', function (Blueprint $table) {
            $table->foreignUlid('driver_profile_id')->primary()->constrained('driver_profiles', 'user_id')->cascadeOnDelete();
            $table->unsignedInteger('outstanding_fee_piastres')->default(0);
            $table->unsignedBigInteger('lifetime_earnings_piastres')->default(0);
            $table->dateTime('last_settled_at')->nullable();
            $table->boolean('is_blocked_from_publishing')->default(false);
            $table->string('block_reason', 255)->nullable();
            $table->dateTime('reconciled_at')->nullable();
            $table->timestamps();
        });
    }
};
