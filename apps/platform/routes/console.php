<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The application schedule.
 *
 * This file IS the production maintenance contract. Everything the platform needs done on a timer lives
 * here, in source control, and the hosting account contributes exactly one thing: a system cron entry
 * that runs `php artisan schedule:run` every minute (docs/runbooks/production-readiness.md). Individual
 * server cron jobs per task are deliberately avoided — they are invisible to review, drift between
 * environments, and are lost on a host migration. The same schedule works on cPanel today and on a VPS
 * later without change; development runs it through `schedule:work` in the scheduler container.
 *
 * `withoutOverlapping` matters because the tick is a minute: a slow sweep must not have a second copy
 * started on top of it. `onOneServer` is deliberately NOT set — it needs a shared atomic cache lock and
 * there is one web host (ADR 0010); adding it would imply a topology the platform does not have.
 */
Schedule::command('identity:prune-expired')
    ->hourly()
    ->withoutOverlapping()
    ->description('Remove expired transient Identity state (ADR 0016, ADR 0023).');

/*
 * Resources' orphaned files (ADR 0037, decision 61): files no asset row refers to, left by a removal that failed after its deletion
 * committed or by an upload whose change failed. Deleted content must not linger in the store, so this is scheduled rather than left
 * to an operator. It removes only unreferenced files older than a day, never one a row refers to, and the scheduler does not run it
 * while the application is in maintenance mode, which is when a restore happens (docs/runbooks/backup-and-restore.md).
 */
Schedule::command('resources:assets:prune')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->description('Remove Resources files no asset refers to (ADR 0037).');
