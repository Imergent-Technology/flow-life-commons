<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Discussions: `discussions`, the header of a Guardian thread (ADR 0035). The words are in `discussion_messages`.
 *
 * - `state` is a validated string, `open` or `resolved`, never a database enum (charter rule 13).
 * - `message_count` is the last allocated message sequence. It counts tombstones, since each keeps its place. It and
 *   `last_activity_at` are written only by a reply, under this row's lock.
 * - `last_activity_at` is when a message was last POSTED. Edits, removals, retitling, resolving and reopening do not move it.
 * - The creator is not stored here: it is the author of the discussion's opening message (sequence 1).
 * - `resolved_by_person_id` is provenance and has NO foreign key (ADR 0021). It is a Person, not an Account.
 * - Instants are UTC DATETIME columns written from the domain (charter rule 14).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('title', 200);
            $table->string('state', 16);
            $table->integer('message_count');
            $table->dateTime('last_activity_at');
            $table->dateTime('resolved_at')->nullable();
            $table->char('resolved_by_person_id', 26)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            // The list: most recently active first, id breaking ties; and the same list for one state.
            $table->index(['last_activity_at', 'id'], 'discussions_activity_index');
            $table->index(['state', 'last_activity_at', 'id'], 'discussions_state_activity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussions');
    }
};
