<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `accounts`, a Person's means of signing in (ADR 0015, ADR 0021).
 *
 * - `email` is presentation data. `email_canonical` (lowercase, computed by
 *   EmailAddress in application code) is the only lookup key and carries the
 *   uniqueness. Never rely on the collation to fold case: it differs per engine.
 * - `status` is a VARCHAR backed by a PHP enum, never a database ENUM.
 * - The password is columns on the account; `password_hash` is null until the
 *   invitation is accepted.
 * - person_id -> people.id is RESTRICT: an Account cannot outlive or orphan its Person.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('person_id');
            $table->string('email', 254);
            $table->string('email_canonical', 254);
            $table->dateTime('email_verified_at')->nullable();
            $table->string('password_hash')->nullable();
            $table->dateTime('password_updated_at')->nullable();
            $table->string('status', 16);
            $table->dateTime('disabled_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            // Named explicitly: the repository recognises a violation by constraint name.
            $table->unique('email_canonical', 'accounts_email_canonical_unique');
            // One Account per Person. Declared before the foreign key so it serves as its index.
            $table->unique('person_id', 'accounts_person_id_unique');

            $table->foreign('person_id')->references('id')->on('people')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
