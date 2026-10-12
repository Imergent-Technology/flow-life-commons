<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Crm: `contact_methods.updated_by_account_id`, the Account that last changed a method's value, label or primary
 * flag (ADR 0038, P9).
 *
 * - Provenance, not a reference: NO foreign key (ADR 0021), exactly as `contact_profiles.updated_by_account_id`.
 *   It names an Account, not a Person.
 * - Nullable. Existing rows keep NULL, which means "before provenance was recorded". There is no backfill because
 *   no truthful source for it exists, and the profile's provenance (who last edited the profile fields) is a
 *   different fact that is never copied here.
 * - Portable: one nullable fixed-width column, no index, no default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contact_methods', function (Blueprint $table): void {
            $table->char('updated_by_account_id', 26)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contact_methods', function (Blueprint $table): void {
            $table->dropColumn('updated_by_account_id');
        });
    }
};
