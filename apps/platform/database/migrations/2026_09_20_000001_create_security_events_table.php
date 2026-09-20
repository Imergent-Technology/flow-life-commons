<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Audit: `security_events`, the append-only record of identity- and access-relevant
 * occurrences (ADR 0019).
 *
 * - NO foreign keys, ever (ADR 0021): audit must outlive its subjects and must never
 *   block an operation. The *_id columns are plain references.
 * - Append-only is enforced in code (there is no update or delete path), because
 *   triggers are not portable.
 * - `context` is JSON but is never queried into: JSON functions differ per engine.
 * - `actor_client_id` is part of the frozen schema for API clients, which do not exist
 *   yet; nothing writes it in this phase.
 * - No indexes yet: nothing reads this table. Reviewing and retention are later concerns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->dateTime('occurred_at');
            $table->string('type', 64);
            $table->char('actor_account_id', 26)->nullable();
            $table->char('actor_client_id', 26)->nullable();
            $table->char('subject_person_id', 26)->nullable();
            $table->char('subject_account_id', 26)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('outcome', 16);
            $table->json('context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
