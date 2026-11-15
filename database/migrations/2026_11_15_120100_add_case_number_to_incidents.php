<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('case_number', 12)->nullable()->unique()->after('id');
        });

        $taken = [];

        DB::table('incidents')->whereNull('case_number')->orderBy('id')->pluck('id')->each(function (string $id) use (&$taken): void {
            do {
                $number = sprintf('RP-%06d', random_int(0, 999_999));
            } while (isset($taken[$number]));

            $taken[$number] = true;

            DB::table('incidents')->where('id', $id)->update(['case_number' => $number]);
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropUnique(['case_number']);
            $table->dropColumn('case_number');
        });
    }
};
