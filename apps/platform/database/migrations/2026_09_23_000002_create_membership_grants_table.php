<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Membership: `membership_grants`, time-bounded grants of membership access (ADR 0028).
 *
 * - Current membership is DERIVED from these rows at query time; there is no status column
 *   and no expiry job. A grant is a non-deleting history with explicit one-way revocation:
 *   after creation only revoked_at/revoked_by_account_id ever change, and only once, together,
 *   via a race-safe conditional UPDATE (ADR 0025's precedent) rather than this table ever
 *   being rewritten wholesale.
 * - Intervals are half-open [starts_at, ends_at). ends_at NULL means explicitly open-ended
 *   (honorary, founding, legacy or other indefinite access), never "no end date entered yet".
 * - person_id -> people.id is a cross-module foreign key, RESTRICT (ADR 0021): a grant
 *   pointing at a non-existent person is a dangling entitlement, not untidiness. This
 *   migration must therefore run after Identity's people migration.
 * - granted_by_account_id and revoked_by_account_id are provenance and have NO foreign key
 *   (ADR 0021): they record what happened and must neither block an operation nor be broken
 *   by one.
 * - There is deliberately NO unique constraint on (source, source_reference) (ADR 0029):
 *   provenance and idempotency are different concerns, and legitimate refund/correction/
 *   regrant flows may cite the same business reference more than once. source_reference is an
 *   opaque provenance handle only, never parsed and never Person identity.
 * - No payment/provider business fields: amount, currency, method, status and subscription
 *   mechanics belong to the external commerce provider, never to this table (ADR 0029).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_grants', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('person_id');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('source', 32);
            $table->string('source_reference', 191)->nullable();
            $table->char('granted_by_account_id', 26)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->char('revoked_by_account_id', 26)->nullable();
            $table->dateTime('created_at');

            // Declared before the foreign key so both engines have the same index
            // (PostgreSQL does not create one for a foreign key on its own).
            $table->index('person_id', 'membership_grants_person_id_index');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_grants');
    }
};
