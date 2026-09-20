<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Access: `role_assignments`, the only authorization data that persists (ADR 0017).
 * Roles and capabilities are code; there is no roles table and no role_capabilities table.
 *
 * - A row's existence means the grant is ACTIVE. Revocation deletes it. There is no
 *   revoked_at, no soft delete, no scope and no history: the history is the audit trail.
 * - person_id -> people.id is a deliberate CROSS-MODULE foreign key with RESTRICT
 *   (ADR 0021): a grant pointing at a non-existent person is a dangling privilege, and
 *   RESTRICT forces an explicit, audited revocation before a Person can be removed.
 *   That is why this migration must run after Identity's (2026_09_19_000001).
 * - granted_by_account_id is provenance, so it has NO foreign key (ADR 0021). It is null
 *   when the platform itself makes the grant (the administrator bootstrap).
 * - role_key is a VARCHAR holding a PHP enum's value, never a database ENUM. Whether a
 *   stored key still means anything is the code catalog's decision, and one that does not
 *   grants nothing. (MariaDB compares VARCHAR case-insensitively and PostgreSQL does not,
 *   so a corrupt, wrongly-cased key can be stored on one and not the other, but the
 *   catalog matches keys exactly, so on either engine it simply grants nothing.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('person_id');
            $table->string('role_key', 64);
            $table->char('granted_by_account_id', 26)->nullable();
            $table->dateTime('granted_at');

            // One grant per (person, role). Its leading person_id column also serves the
            // per-person lookup and the foreign key, so no separate index is needed.
            $table->unique(['person_id', 'role_key'], 'role_assignments_person_id_role_key_unique');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
