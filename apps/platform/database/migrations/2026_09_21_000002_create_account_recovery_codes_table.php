<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `account_recovery_codes`, single-use recovery codes (ADR 0023).
 *
 * - Only `code_hash` is stored (lowercase hex SHA-256, bound to the Account). The raw code is shown once
 *   and never again, and is never stored encrypted so that it could be shown again.
 * - One row per code, so consuming one is a single conditional UPDATE ("this code, still unused") that
 *   is atomic on both engines, with no JSON and no locking tricks. `used_at` is the one-time flag.
 * - account_id -> accounts.id is RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_recovery_codes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('account_id');
            $table->string('code_hash', 64);
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at');
            // The lookup key of consumption, and it makes a duplicate code for one Account impossible.
            $table->unique(['account_id', 'code_hash'], 'account_recovery_codes_account_id_code_hash_unique');
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_recovery_codes');
    }
};
