<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resources: `resource_cards` and `resource_card_audiences` (ADR 0037).
 *
 * - `type` is a validated string, fixed at creation. WP1 writes `basic` and `external_link`; `file` is the third approved
 *   type and arrives with managed files (WP3), which adds `asset_id` and `resource_assets` by its own migration. Nothing
 *   here needs to change for it.
 * - `content_document` is the canonical JSON text of the validated document, in a `mediumText` column and NEVER a `json()`
 *   column: Laravel's `json()` is `longtext` on MariaDB and `json` on PostgreSQL, with different semantics, for a value that is
 *   never queried inside (measured in the design gate). `content_format` and `content_version` name the profile it follows.
 * - `summary_text` is stored (not computed on read); `summary_mode` says whether content changes may rewrite it.
 * - `audience_mode` is `inherit` or `narrowed`. Rows in `resource_card_audiences` exist only for a narrowed Card, and at
 *   least one must; the domain holds that, not a constraint. Absence of rows never means "inherit": the mode is explicit, so
 *   losing the last row cannot silently broaden a Card.
 * - Creators and editors are Person ids and PROVENANCE, with no foreign key (ADR 0021). Every foreign key is within Resources.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_cards', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->char('pack_id', 26);
            $table->integer('position');
            $table->string('type', 32);
            $table->string('title', 200);
            $table->string('summary_mode', 16);
            $table->string('summary_text', 300);
            $table->string('content_format', 32);
            $table->integer('content_version');
            $table->mediumText('content_document');
            $table->string('external_uri', 2048)->nullable();
            $table->string('audience_mode', 16);
            $table->string('state', 16);
            $table->integer('revision');
            $table->char('created_by_person_id', 26);
            $table->char('updated_by_person_id', 26);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['pack_id', 'position', 'id'], 'resource_cards_order_index');
            $table->foreign('pack_id', 'resource_cards_pack_id_foreign')->references('id')->on('resource_packs')->restrictOnDelete();
        });

        Schema::create('resource_card_audiences', function (Blueprint $table): void {
            $table->char('card_id', 26);
            $table->string('audience', 32);

            $table->primary(['card_id', 'audience']);
            $table->foreign('card_id', 'resource_card_audiences_card_id_foreign')->references('id')->on('resource_cards')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_card_audiences');
        Schema::dropIfExists('resource_cards');
    }
};
