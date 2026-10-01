<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Crm: `contact_tags` and `contact_tag_assignments` (ADR 0034). Tags are user-managed LABELS and nothing more: a tag
 * never grants a capability or establishes Membership, Volunteer status or Guardian access. The starter vocabulary is
 * data, never an enum.
 *
 * - `name_canonical` carries the uniqueness (lower-cased, whitespace collapsed), for the same reason Identity keeps
 *   `email_canonical`: the engines collate a plain VARCHAR differently, so uniqueness must not depend on collation.
 * - An assignment is unique per (person_id, tag_id). tag_id -> contact_tags.id is within Crm and RESTRICT, so a tag
 *   that is still in use cannot be deleted out from under its assignments. person_id -> people.id is a cross-module
 *   foreign key, RESTRICT (ADR 0021). created_by / assigned_by are provenance with NO foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_tags', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 64);
            $table->string('name_canonical', 64)->unique('contact_tags_name_canonical_unique');
            $table->char('created_by_account_id', 26)->nullable();
            $table->dateTime('created_at');
        });

        Schema::create('contact_tag_assignments', function (Blueprint $table): void {
            $table->char('person_id', 26);
            $table->char('tag_id', 26);
            $table->char('assigned_by_account_id', 26)->nullable();
            $table->dateTime('assigned_at');

            $table->primary(['person_id', 'tag_id']);
            // The tag filter looks up Persons by tag.
            $table->index('tag_id', 'contact_tag_assignments_tag_id_index');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
            $table->foreign('tag_id')->references('id')->on('contact_tags')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_tag_assignments');
        Schema::dropIfExists('contact_tags');
    }
};
