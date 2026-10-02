<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Discussions: `discussion_messages`, everything anyone wrote in a discussion, the opening message (sequence 1) included
 * (ADR 0035).
 *
 * - `sequence` is the order within a discussion. It is allocated under the discussion row's lock and never reused or
 *   renumbered; time is not the order, because DATETIME holds whole seconds on both engines. `unique(discussion_id,
 *   sequence)` is the backstop, and is also the index messages are paged by.
 * - `author_person_id`, `edited_by_person_id` are provenance and have NO foreign key (ADR 0021): a message must neither block
 *   a future Person merge or anonymisation nor be broken by one. They are Persons, not Accounts. The author never changes.
 * - `body` is NULL exactly when `removed_at` is set. That is enforced in the domain and by tests rather than a CHECK
 *   constraint (portability). A removed message keeps its row, place and author; its text is nowhere else.
 * - `edited_at` is set by an edit that changed the text; there is no `updated_at`, because each meaningful change has its own
 *   column, and "edited" must not be inferred from a timestamp a removal also moves.
 * - discussion_id -> discussions.id is within the module: RESTRICT, and no discussion is deleted in Phase 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussion_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('discussion_id', 26);
            $table->integer('sequence');
            $table->char('author_person_id', 26);
            $table->text('body')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('edited_at')->nullable();
            $table->char('edited_by_person_id', 26)->nullable();
            $table->dateTime('removed_at')->nullable();

            $table->unique(['discussion_id', 'sequence'], 'discussion_messages_sequence_unique');

            $table->foreign('discussion_id')->references('id')->on('discussions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussion_messages');
    }
};
