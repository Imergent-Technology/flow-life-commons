<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resources: `resource_assets` and `resource_cards.asset_id`, the managed files File Cards own (ADR 0037, decisions 62-63, WP3).
 *
 * - An asset is the metadata of one file: its id, the storage key derived from that id alone (`unique`), the sanitised name it was
 *   uploaded under, the media type DETECTED from its content and allowlisted, its size, a SHA-256 digest of its bytes, and who
 *   uploaded it and when. The bytes are not here: they are in the private `resources` disk, under the storage key, and are data of
 *   record outside the database (backups take both together).
 * - No path is stored. The storage key is not a path and contains none; the disk decides where a key lives.
 * - Ownership runs FROM the Card: `resource_cards.asset_id` is `unique`, so no asset can ever belong to two Cards, and it is a
 *   foreign key to `resource_assets.id` with RESTRICT, so an asset row cannot be deleted while a Card still points at it. It is
 *   nullable because only File Cards have one; the domain requires it for every File Card (a CHECK constraint is outside the
 *   portable schema). Both engines admit any number of NULLs under a unique index (measured in the design gate).
 * - `uploaded_by_person_id` is provenance, with no foreign key (ADR 0021). Every foreign key is within Resources, and none cascades:
 *   deletion is an explicit use case that must enumerate the assets anyway, to remove their files after it commits (decision 60).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('storage_key', 64)->unique('resource_assets_storage_key_unique');
            $table->string('original_filename', 255);
            $table->string('media_type', 127);
            $table->bigInteger('byte_size');
            $table->char('sha256', 64);
            $table->char('uploaded_by_person_id', 26);
            $table->dateTime('created_at');
        });

        Schema::table('resource_cards', function (Blueprint $table): void {
            $table->char('asset_id', 26)->nullable();

            $table->unique('asset_id', 'resource_cards_asset_id_unique');
            $table->foreign('asset_id', 'resource_cards_asset_id_foreign')->references('id')->on('resource_assets')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('resource_cards', function (Blueprint $table): void {
            $table->dropForeign('resource_cards_asset_id_foreign');
            $table->dropUnique('resource_cards_asset_id_unique');
            $table->dropColumn('asset_id');
        });
        Schema::dropIfExists('resource_assets');
    }
};
