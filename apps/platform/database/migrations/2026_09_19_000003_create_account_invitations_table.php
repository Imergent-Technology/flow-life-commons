<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `account_invitations`, the invite-only account-creation model
 * (docs/architecture/identity-and-access.md, "Credential lifecycles").
 *
 * - The bearer token is never stored, only `token_hash` (lowercase hex SHA-256).
 * - Expiry is `expires_at`; one-time use is `accepted_at`. Revocation is deleting the row.
 * - account_id -> accounts.id is RESTRICT.
 * - `invited_by_account_id` is provenance and deliberately has NO foreign key
 *   (ADR 0021): it records what happened and must neither block an operation nor be
 *   broken by one. It is null when the platform itself issues the invitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id');
            $table->string('token_hash', 64);
            $table->dateTime('expires_at');
            $table->dateTime('accepted_at')->nullable();
            $table->char('invited_by_account_id', 26)->nullable();

            $table->unique('token_hash', 'account_invitations_token_hash_unique');
            // Declared before the foreign key so both engines have the same index
            // (PostgreSQL does not create one for a foreign key on its own).
            $table->index('account_id', 'account_invitations_account_id_index');

            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
