<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `account_totp_factors`, an Account's authenticator (TOTP) enrolment (ADR 0023).
 *
 * - One row per Account (unique account_id). account_id -> accounts.id is RESTRICT.
 * - The secrets are stored ENCRYPTED by the application (Laravel's authenticated encryption, under the
 *   application key) as opaque text. The database never sees a key and does no cryptography, so the
 *   schema is identical on MariaDB and PostgreSQL. A TOTP secret cannot be hashed: the server must use
 *   it to check codes.
 * - `secret_ciphertext` is the ACTIVE secret and `enrolled_at` when it was first proved; both are null
 *   until then. `pending_*` is a generated but unproved secret (a first enrolment, or a replacement
 *   while the active one still works). A row always holds one or both.
 * - `last_used_step` is the last accepted TOTP time step, so a code cannot be replayed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_totp_factors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id');
            $table->text('secret_ciphertext')->nullable();
            $table->text('pending_secret_ciphertext')->nullable();
            $table->dateTime('pending_started_at')->nullable();
            $table->dateTime('enrolled_at')->nullable();
            $table->bigInteger('last_used_step')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->unique('account_id', 'account_totp_factors_account_id_unique');
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_totp_factors');
    }
};
