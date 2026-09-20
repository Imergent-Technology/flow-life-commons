<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `password_reset_tokens`, Laravel's database-backed reset-token store, adapted for
 * accounts (docs/architecture/identity-and-access.md, "Credential lifecycles"). Transient by nature.
 *
 * - `email` holds the Account's `email_canonical`, never the address as entered. Laravel keys a reset
 *   by the identifier it is given, so the canonical form is what keeps the lookup identical on
 *   MariaDB and PostgreSQL (a case-insensitive collation on one and not the other must not decide
 *   whether two spellings are the same Account; ADR 0015). It is the primary key: one live token
 *   per Account, and issuing another replaces it.
 * - `token` is a hash of the token (Laravel's own hashed-token behaviour). The raw token exists only
 *   in the message sent to the Account's owner and is never stored.
 * - `created_at` is a DATETIME written in UTC, like every instant here; expiry is measured from it.
 * - No foreign key: this is framework-shaped, short-lived state, and it must neither block nor be
 *   broken by an Account operation (ADR 0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
