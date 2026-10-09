<?php

declare(strict_types=1);

use App\Modules\Access\Infrastructure\RenameGuardianRoleAssignments;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * ADR 0038, M6. Renames stored `guardian` role assignments to `guardian-full`.
 *
 * The release that contains this migration is restore-required (ADR 0027). Rolling back
 * only the application code after the keys have moved fails closed: the previous catalog
 * does not know `guardian-full`, so every former Guardian-role user loses that bundle.
 * Recovery is the pre-migration backup. down() is for a development database only.
 *
 * Effective capabilities are unchanged: the new key is the same bundle. No row is created,
 * no other key is touched, no security event is written, and no relationship is created.
 * Historical role.granted events keep the key they recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new RenameGuardianRoleAssignments)->up(DB::connection());
    }

    public function down(): void
    {
        (new RenameGuardianRoleAssignments)->down(DB::connection());
    }
};
