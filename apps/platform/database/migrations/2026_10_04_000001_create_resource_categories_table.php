<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resources: `resource_categories` (ADR 0037). A Category is global organizational metadata: a name and a place in the order.
 * It is not an audience and not authorization.
 *
 * - `name_canonical` carries the uniqueness (lower-cased, whitespace collapsed), as `contact_tags.name_canonical` does, so
 *   ASCII and case behave identically on both engines. Non-ASCII folding follows each engine's collation, as for CRM tags.
 * - `position` is the manual order, and is NOT unique: a unique index would make every reorder a two-phase update, and
 *   `(position, id)` is a total order without it (decision 9).
 * - Creators and editors are Person ids and PROVENANCE, with no foreign key (ADR 0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 80);
            $table->string('name_canonical', 80)->unique('resource_categories_name_canonical_unique');
            $table->integer('position');
            $table->char('created_by_person_id', 26);
            $table->char('updated_by_person_id', 26);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');

            $table->index(['position', 'id'], 'resource_categories_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_categories');
    }
};
