<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Stored as a plain VARCHAR backed by this enum; a database ENUM would fail the
 * portability guardrail (ADR 0005).
 */
enum AccountStatus: string
{
    /** Created by invitation; no credential yet, cannot authenticate. */
    case Invited = 'invited';

    /** Has accepted the invitation and set a password. */
    case Active = 'active';

    /** Cannot authenticate. The Person, their assignments and history are untouched. */
    case Disabled = 'disabled';
}
