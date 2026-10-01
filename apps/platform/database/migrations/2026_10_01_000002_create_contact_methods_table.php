<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Crm: `contact_methods`, the emails and phone numbers a Guardian has recorded for a Person (ADR 0034).
 *
 * - NOT unique across Persons: a shared household or organisational address is legitimate. Uniqueness is per Person
 *   only: `unique(person_id, kind, search_value)`, so the same value cannot be recorded twice for one Person.
 * - `value` is what the human entered (trimmed); `search_value` is a conservative normalisation for matching and
 *   search only, never an identity (an email is lower-cased; a phone number keeps its digits and a leading plus).
 * - "At most one primary per kind per Person" is enforced by the database, not by check-then-write: `primary_kind`
 *   holds the kind when the row is the primary and NULL otherwise, and `unique(person_id, primary_kind)` allows any
 *   number of NULLs and one of each kind on both MariaDB and PostgreSQL. No partial index, no generated column.
 * - person_id -> people.id is a cross-module foreign key, RESTRICT (ADR 0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('person_id', 26);
            $table->string('kind', 16);
            $table->string('value', 255);
            $table->string('search_value', 255);
            $table->string('label', 64)->nullable();
            $table->string('primary_kind', 16)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->unique(['person_id', 'kind', 'search_value'], 'contact_methods_person_kind_value_unique');
            $table->unique(['person_id', 'primary_kind'], 'contact_methods_one_primary_per_kind_unique');
            // Searching by value across Persons (a leading-wildcard LIKE cannot use it; an exact match can).
            $table->index(['kind', 'search_value'], 'contact_methods_kind_search_value_index');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_methods');
    }
};
