<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resources: `resource_packs` and `resource_pack_audiences` (ADR 0037).
 *
 * - `category_id` is nullable (a Draft Pack may have none) and required while Published, which the domain enforces; it is a
 *   foreign key within Resources with RESTRICT, so a Category that is in use cannot be deleted from under its Packs.
 * - `state` is a validated string, never a database enum (charter rule 13). `revision` is the optimistic-concurrency token of
 *   the authored fields (decision 56).
 * - `position` orders Packs within their Category, with `id` as the tie-break, and is not unique (decision 9). (A unique
 *   index over `(category_id, position)` would not constrain the uncategorised Drafts anyway: both engines treat NULLs as
 *   distinct, which was measured while designing this.)
 * - A Pack's audiences are rows in `resource_pack_audiences`, keyed `(pack_id, audience)`: an audience is a code-owned string.
 * - Creators and editors are Person ids and PROVENANCE, with no foreign key (ADR 0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_packs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('category_id', 26)->nullable();
            $table->integer('position');
            $table->string('title', 200);
            $table->string('summary', 300)->nullable();
            $table->boolean('is_series')->default(false);
            $table->string('state', 16);
            $table->integer('revision');
            $table->char('created_by_person_id', 26);
            $table->char('updated_by_person_id', 26);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['category_id', 'position', 'id'], 'resource_packs_order_index');
            $table->index('state', 'resource_packs_state_index');
            $table->foreign('category_id', 'resource_packs_category_id_foreign')->references('id')->on('resource_categories')->restrictOnDelete();
        });

        Schema::create('resource_pack_audiences', function (Blueprint $table): void {
            $table->char('pack_id', 26);
            $table->string('audience', 32);

            $table->primary(['pack_id', 'audience']);
            // The management filter looks up Packs by audience.
            $table->index('audience', 'resource_pack_audiences_audience_index');
            $table->foreign('pack_id', 'resource_pack_audiences_pack_id_foreign')->references('id')->on('resource_packs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_pack_audiences');
        Schema::dropIfExists('resource_packs');
    }
};
