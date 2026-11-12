<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Team notes on a member (D01, D10): what staff learned about a person, for the next
 * staff member who opens them. Internal: never shown to the member, never in the API.
 * Append-only — a note is corrected by writing another.
 *
 * Built the way admin_invitations had to be (2026_11_12_200000): a half-made table from a
 * failed run is rebuilt, and the foreign keys copy the referenced id columns' character
 * set and collation, which on the hosted database differ from the server default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('member_notes');

        $users = $this->idColumn('users');
        $admins = $this->idColumn('admin_users');

        Schema::create('member_notes', function (Blueprint $table) use ($users, $admins) {
            $match = fn ($column, ?object $like) => $like === null ? $column : $column->charset($like->charset)->collation($like->collation);

            if ($users !== null) {
                $table->charset = $users->charset;
                $table->collation = $users->collation;
            }

            $match($table->ulid('id'), $users)->primary();
            $match($table->foreignUlid('user_id'), $users)->constrained('users')->cascadeOnDelete();
            $match($table->foreignUlid('admin_id'), $admins)->nullable()->constrained('admin_users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_notes');
    }

    private function idColumn(string $table): ?object
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $row = DB::selectOne(
            'select CHARACTER_SET_NAME as charset, COLLATION_NAME as collation
               from information_schema.COLUMNS
              where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            [$table, 'id'],
        );

        return $row !== null && $row->charset !== null && $row->collation !== null ? $row : null;
    }
};
