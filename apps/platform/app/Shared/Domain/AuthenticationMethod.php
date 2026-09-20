<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * How an Actor proved who they are. Only the Guardian Console's session exists so far, in two strengths:
 * the password alone, or the password and a second factor (ADR 0023). The Actor records which, so what a
 * request was authenticated with is never guessed. It carries no factor material.
 */
enum AuthenticationMethod: string
{
    /** A Console session established by a password alone: an Account whose access does not need more. */
    case Session = 'session';

    /** A Console session established by a password AND a second factor (an authenticator code, a recovery code, or enrolment). */
    case SessionWithSecondFactor = 'session_second_factor';
}
