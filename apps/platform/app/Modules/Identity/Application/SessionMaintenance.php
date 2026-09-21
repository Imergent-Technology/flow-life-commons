<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use DateTimeImmutable;

/**
 * Housekeeping over the whole session store, as opposed to AccountSessions, which is about the sessions
 * of one Account and is a security operation.
 *
 * Sessions live in the database (ADR 0016) and every visitor gets a row, signed in or not: the Console's
 * first request asks for a request-forgery token, and a refused sign-in leaves one behind. Those rows
 * are worthless the moment they go idle, but nothing in the framework removes them except a per-request
 * lottery, which is neither deterministic nor something a production request should be paying for.
 */
interface SessionMaintenance
{
    /**
     * Removes every session whose last activity is before `$idleBefore`, and returns how many.
     *
     * The cutoff is the caller's, and must be the inactivity lifetime, so that a session this removes
     * is one the framework would already refuse. It can therefore never end a usable session, and
     * running it twice, or ten times, changes nothing after the first.
     */
    public function pruneIdleBefore(DateTimeImmutable $idleBefore): int;
}
