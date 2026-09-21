<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `accounts.security_generation`, the Account's monotonic security generation (ADR 0025).
 *
 * Every operation that ends an Account's sessions advances it in the same transaction; a session
 * carries the generation its authentication proof was checked against, and stops being usable the
 * moment the two differ. That is what closes the window between a proof committing and the transport
 * writing the session row, which deleting session rows cannot reach.
 *
 * Deliberately a plain unsigned integer with a default, not a nullable one: it is incremented by
 * `UPDATE ... SET security_generation = security_generation + 1`, which both engines apply atomically
 * on the locked row, and a row that has never been touched still reads as generation 1 rather than
 * "unknown". It is NOT part of the Account aggregate (nothing in Identity\Domain names it), so no
 * whole-row save can carry a stale value back over a concurrent advance.
 *
 * Deploying this invalidates every session that existed before it: those carry no generation, and the
 * enforcement fails safe. See docs/runbooks/production-readiness.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->unsignedBigInteger('security_generation')->default(1)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn('security_generation');
        });
    }
};
