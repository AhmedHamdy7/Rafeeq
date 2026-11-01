<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `vehicles.transmission` becomes `manual` | `automatic` (follow-up F2-5).
 *
 * The column stays a varchar (binding standard #4: varchar + PHP enum, never MySQL ENUM); what
 * changes is that every row must now load through the `Transmission` cast. Rows written while it
 * was free text are mapped where the meaning is unambiguous — "Auto", "AT", "أوتوماتيك" — and set
 * to null otherwise, which the app already treats as "not given". A guess would be worse than a
 * blank: the gearbox is what a passenger who only drives automatics reads before she offers to
 * drive.
 *
 * Not reversible in the data sense; `down()` has nothing to restore and does nothing.
 */
return new class extends Migration
{
    private const array AUTOMATIC = ['automatic', 'auto', 'at', 'أوتوماتيك', 'اوتوماتيك', 'automatique'];

    private const array MANUAL = ['manual', 'man', 'mt', 'stick', 'مانيوال', 'مانوال', 'عادي'];

    public function up(): void
    {
        DB::table('vehicles')->whereNotNull('transmission')->orderBy('id')->each(function (object $row): void {
            $value = mb_strtolower(trim((string) $row->transmission));

            $mapped = match (true) {
                in_array($value, self::AUTOMATIC, true) => 'automatic',
                in_array($value, self::MANUAL, true) => 'manual',
                default => null,
            };

            if ($mapped !== $row->transmission) {
                DB::table('vehicles')->where('id', $row->id)->update(['transmission' => $mapped]);
            }
        });
    }

    public function down(): void
    {
        //
    }
};
