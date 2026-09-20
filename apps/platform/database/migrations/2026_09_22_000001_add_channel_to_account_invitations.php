<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Identity: how an invitation reached its holder (ADR 0024).
 *
 * `channel` is a VARCHAR backed by a PHP enum, never a database ENUM. `operator` means somebody handed the
 * token over (the administrator bootstrap prints it to a server operator), so accepting it shows nothing
 * about the mailbox. `email` means the platform mailed it to the Account's own address, so accepting it does.
 * Every invitation that exists before this migration was handed over by an operator, which is what the
 * default says; a new invitation states its channel explicitly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_invitations', function (Blueprint $table): void {
            $table->string('channel', 16)->default('operator');
        });
    }

    public function down(): void
    {
        Schema::table('account_invitations', function (Blueprint $table): void {
            $table->dropColumn('channel');
        });
    }
};
