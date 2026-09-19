<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔒 PCI: a card number never touches this server — tokenization happens
     * at Paymob directly from the app. `provider_token` is the only thing
     * stored (Bible §4, group ⑨).
     */
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 20)->default('paymob');
            $table->string('provider_token', 255);
            $table->string('type', 20); // card|wallet|instapay
            $table->char('last4', 4)->nullable(); // display only
            $table->string('brand', 20)->nullable();
            $table->string('wallet_phone_masked', 20)->nullable();
            $table->boolean('is_default')->default(false);
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
