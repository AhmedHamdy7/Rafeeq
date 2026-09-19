<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('category', 50);
            $table->string('priority', 20);
            $table->string('status', 20)->default('open');
            $table->foreignUlid('assigned_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->dateTime('sla_due_at')->nullable();
            $table->string('related_entity_type', 50)->nullable();
            $table->ulid('related_entity_id')->nullable(); // polymorphic, no FK
            $table->timestamps();

            $table->index(['status', 'priority']);
            $table->index('assigned_admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
