<?php

use App\Domains\Geo\Support\PlaceNameMatch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->json('aliases')->nullable()->after('name_ar');
            $table->text('search_name')->nullable()->after('aliases');
            $table->unsignedInteger('radius_metres')->nullable()->after('lng');
        });

        foreach (DB::table('places')->select(['id', 'name', 'name_ar'])->cursor() as $place) {
            DB::table('places')->where('id', $place->id)->update([
                'search_name' => PlaceNameMatch::searchName([$place->name, $place->name_ar]),
            ]);
        }

        Schema::create('place_search_misses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->nullable();
            $table->string('query', 100);
            $table->string('normalised_query', 100)->index();
            $table->decimal('lat', 5, 2)->nullable();
            $table->decimal('lng', 5, 2)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('place_search_misses');

        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn(['aliases', 'search_name', 'radius_metres']);
        });
    }
};
