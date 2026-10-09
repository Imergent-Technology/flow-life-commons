<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Access: `sourced_role_grants` (ADR 0038, K4). A role an authorized operator granted
 * because of one specific source instance. Independent assignments stay in
 * `role_assignments` and are never written here.
 *
 * - A row's existence means the grant is active. Withdrawal deletes it.
 * - person_id -> people.id is RESTRICT, as on role_assignments (ADR 0021).
 * - source_id has NO foreign key. Access's schema must not depend on the source's module.
 * - granted_by_account_id is provenance and has no foreign key.
 * - unique(source_type, source_id, role_key): one grant of a role per source instance.
 *   The same role from another source, or from role_assignments, is a different row.
 * - An unknown role_key grants nothing. The catalog decides that, not a database ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sourced_role_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('person_id');
            $table->string('role_key', 64);
            $table->string('source_type', 32);
            $table->char('source_id', 26);
            $table->char('granted_by_account_id', 26)->nullable();
            $table->dateTime('granted_at');

            $table->unique(['source_type', 'source_id', 'role_key'], 'sourced_role_grants_source_role_unique');
            $table->index('person_id', 'sourced_role_grants_person_id_index');
            $table->index('role_key', 'sourced_role_grants_role_key_index');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sourced_role_grants');
    }
};
