<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Organizational relationships (ADR 0038, F6, F8, F10, F12).
 *
 * Four tables, all owned by Relationships:
 *
 * - `person_relationships` is one instance per Person and type. The unique index is the intake race.
 *   `revision` starts at 1 and moves on every status change and every field change that changes something.
 *   Provenance person ids have NO foreign key (ADR 0021). `person_id` does: a relationship is about a Person
 *   who exists, and RESTRICT so a Person with a relationship cannot be removed out from under it.
 * - `person_relationship_status_changes` is the whole business history, including the creation row
 *   (`from_status` null). It is not a security event.
 * - `person_relationship_field_values` holds canonical text. Empty is not a value: clearing deletes the row.
 * - `person_relationship_role_provisions` is created for WP2B and unused until then. Deletion still removes
 *   any row that is there, so a later decision cannot outlive its relationship.
 *
 * No JSON, no enum, no CHECK, no trigger, no soft delete. Instants are UTC DATETIME.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_relationships', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('person_id', 26);
            $table->string('relationship_type', 32);
            $table->string('status', 16);
            $table->unsignedInteger('revision');
            $table->dateTime('status_changed_at');
            $table->char('status_changed_by', 26);
            $table->dateTime('created_at');
            $table->char('created_by', 26);
            $table->dateTime('updated_at');
            $table->char('updated_by', 26);

            $table->unique(['person_id', 'relationship_type'], 'person_relationships_person_type_unique');
            $table->index(['relationship_type', 'status'], 'person_relationships_type_status_index');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });

        Schema::create('person_relationship_status_changes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('relationship_id', 26);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->dateTime('changed_at');
            $table->char('changed_by', 26);

            $table->index(['relationship_id', 'changed_at', 'id'], 'person_relationship_status_changes_order_index');

            $table->foreign('relationship_id')->references('id')->on('person_relationships')->restrictOnDelete();
        });

        Schema::create('person_relationship_field_values', function (Blueprint $table): void {
            $table->char('relationship_id', 26);
            $table->string('field_key', 64);
            $table->text('value');
            $table->dateTime('updated_at');
            $table->char('updated_by', 26);

            $table->primary(['relationship_id', 'field_key']);

            $table->foreign('relationship_id')->references('id')->on('person_relationships')->restrictOnDelete();
        });

        Schema::create('person_relationship_role_provisions', function (Blueprint $table): void {
            $table->char('relationship_id', 26);
            $table->string('role_key', 64);
            $table->string('decision', 16);
            $table->dateTime('decided_at');
            $table->char('decided_by', 26);

            $table->primary(['relationship_id', 'role_key']);

            $table->foreign('relationship_id')->references('id')->on('person_relationships')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_relationship_role_provisions');
        Schema::dropIfExists('person_relationship_field_values');
        Schema::dropIfExists('person_relationship_status_changes');
        Schema::dropIfExists('person_relationships');
    }
};
