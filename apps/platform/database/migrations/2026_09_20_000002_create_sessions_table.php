<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `sessions`, the database-backed Guardian Console session store (ADR 0016).
 *
 * Laravel's stock migration declares user_id as foreignId(), a BIGINT. Accounts are
 * identified by 26-character ULIDs (ADR 0006), so it is a string(26) here. It is
 * deliberately not a foreign key: a session is framework state, and revoking an Account
 * must not depend on, or be blocked by, its session rows. It is indexed so a later
 * "sign out everywhere" can find an Account's sessions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('user_id', 26)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
