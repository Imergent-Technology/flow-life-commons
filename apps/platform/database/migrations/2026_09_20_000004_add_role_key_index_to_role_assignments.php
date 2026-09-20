<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Access: an index on role_assignments.role_key.
 *
 * The last-administrator invariant locks "every assignment of platform_administrator"
 * (SELECT ... FOR UPDATE, ADR 0020) and must do so identically on MariaDB and PostgreSQL.
 * With no index on role_key that predicate is a full scan, and on InnoDB a locking scan locks
 * every row and gap it reads, which would freeze all role administration while an administrator
 * is being removed. The index keeps the lock to that role's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->index('role_key', 'role_assignments_role_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('role_assignments', function (Blueprint $table): void {
            $table->dropIndex('role_assignments_role_key_index');
        });
    }
};
