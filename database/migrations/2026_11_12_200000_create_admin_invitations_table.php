<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time links that bring a staff member through the first sign-in (S05 set a password,
 * S06 set up the authenticator) — after an invitation, or after an Admin resets their
 * access. Only a SHA-256 of the token is stored: the link is shown once, to the Admin who
 * made it, and a copy of this table is worth nothing without it.
 *
 * 🔴 Written to survive two things a hosted database did to its first version:
 * - A failed run can leave the table half-made with no row in `migrations`, so every later
 *   deploy dies on "table already exists". A table found here was made by such a run (a
 *   finished run would be recorded and never come back), holds no invitation anybody can
 *   use, and is rebuilt.
 * - A foreign key needs both columns in the same character set and collation. A database
 *   whose server default differs from the one `admin_users` was created under refuses the
 *   key (errno 150 / 3780). The new id columns copy `admin_users.id` exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('admin_invitations');

        $match = $this->adminIdColumn();

        Schema::create('admin_invitations', function (Blueprint $table) use ($match) {
            if ($match !== null) {
                $table->charset = $match->charset;
                $table->collation = $match->collation;
            }

            $id = fn ($column) => $match === null ? $column : $column->charset($match->charset)->collation($match->collation);

            $id($table->ulid('id'))->primary();
            $id($table->foreignUlid('admin_user_id'))->constrained('admin_users')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $id($table->foreignUlid('invited_by_admin_id'))->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['admin_user_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invitations');
    }

    /** The character set and collation of admin_users.id on MySQL/MariaDB, or null elsewhere. */
    private function adminIdColumn(): ?object
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        $row = DB::selectOne(
            'select CHARACTER_SET_NAME as charset, COLLATION_NAME as collation
               from information_schema.COLUMNS
              where TABLE_SCHEMA = database() and TABLE_NAME = ? and COLUMN_NAME = ?',
            ['admin_users', 'id'],
        );

        return $row !== null && $row->charset !== null && $row->collation !== null ? $row : null;
    }
};
