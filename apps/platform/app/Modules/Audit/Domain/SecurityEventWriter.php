<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

/**
 * The only way events reach storage, and it can only add. There is deliberately no
 * update, delete or read here: append-only is enforced by code shape, because triggers
 * are not available to us (ADR 0019).
 */
interface SecurityEventWriter
{
    public function append(SecurityEvent $event): void;
}
