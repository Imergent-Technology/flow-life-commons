<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: `people`, the canonical human (ADR 0015). Intentionally thin.
 *
 * Identity's migrations must carry earlier timestamps than Access's, because Access's
 * role_assignments references people.id with a foreign key (ADR 0021).
 *
 * Instants are stored as DATETIME rather than TIMESTAMP: application code writes UTC,
 * and DATETIME has no implicit defaults or ON UPDATE behaviour, no dependence on the
 * connection time zone, and none of MariaDB TIMESTAMP's 2038 ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('display_name');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people');
    }
};
