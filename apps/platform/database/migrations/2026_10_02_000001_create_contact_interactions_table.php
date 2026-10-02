<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Crm: `contact_interactions`, the notes and records of conversations a Guardian keeps about a Person (ADR 0034).
 *
 * - CRM business data, NOT security_events. Phase 1 has no private notes: everyone who may view CRM data sees every row.
 * - `occurred_at` is when the contact happened and is what a Person's list is ordered by; `created_at` is when it was
 *   recorded. Instants are UTC DATETIME columns written from the domain (charter rule 14).
 * - person_id -> people.id is a cross-module foreign key, RESTRICT (ADR 0021): a note about a Person that does not exist
 *   is a defect, and a Person is never deleted out from under their notes.
 * - author_person_id and updated_by_person_id are provenance and have NO foreign key (ADR 0021). They are Persons, not
 *   Accounts: the stable human anchor outlives an Account, and a Person's name is the only thing a reader needs.
 * - There is no soft delete and no history: a note is edited in place and removed outright, like a contact method.
 * - No unique index and no "exactly one" rule, so the Person's profile-row lock is not needed to write a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_interactions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('person_id', 26);
            $table->string('kind', 16);
            $table->text('body');
            $table->dateTime('occurred_at');
            $table->char('author_person_id', 26);
            $table->char('updated_by_person_id', 26)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            // A Person's list: filtered by Person, ordered by when it happened (id breaks ties).
            $table->index(['person_id', 'occurred_at', 'id'], 'contact_interactions_person_occurred_index');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_interactions');
    }
};
