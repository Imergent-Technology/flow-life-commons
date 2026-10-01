<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Crm: `contact_profiles`, the CRM's per-Person anchor row (ADR 0034).
 *
 * - One row per Person at most: `person_id` IS the key. CRM data is sparse, so a Person has a row only once there
 *   is CRM data to hold (a profile field, a contact method, a tag assignment); nothing creates one merely because
 *   a Person exists. There is no "is in CRM" flag and no Member, Account or Volunteer status here: those belong to
 *   their owners and are composed beside CRM data, never copied into it.
 * - The row doubles as the per-Person write lock: every CRM mutation for a Person takes it with SELECT ... FOR UPDATE,
 *   which is what serialises "one primary contact method per kind" and the duplicate checks identically on MariaDB
 *   and PostgreSQL. The row may be empty (both fields null).
 * - person_id -> people.id is a cross-module foreign key, RESTRICT (ADR 0021): CRM data about a Person that does not
 *   exist is a defect, and a Person is never deleted out from under it. This migration must run after Identity's.
 * - updated_by_account_id is provenance and has NO foreign key (ADR 0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_profiles', function (Blueprint $table): void {
            $table->char('person_id', 26)->primary();
            $table->text('how_we_know')->nullable();
            $table->string('affiliation', 255)->nullable();
            $table->char('updated_by_account_id', 26)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_profiles');
    }
};
