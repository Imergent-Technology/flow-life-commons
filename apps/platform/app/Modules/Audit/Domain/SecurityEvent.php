<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * One fact about an identity- or access-relevant occurrence. Immutable and append-only:
 * nothing here, or in the writer port, can change or remove an event once recorded.
 *
 * The actor and subject are optional because they often do not exist: a failed login
 * against an unknown address has neither, and no identity is ever fabricated for it.
 * References are plain ids with no foreign key (ADR 0021): audit must outlive its
 * subjects and must never block an operation.
 *
 * `outcome` is a plain string here; its vocabulary is Audit\Application's, because that
 * is the layer other modules are allowed to name.
 */
final readonly class SecurityEvent
{
    public function __construct(
        public SecurityEventId $id,
        public DateTimeImmutable $occurredAt,
        public string $type,
        public string $outcome,
        public ?AccountId $actorAccountId,
        public ?PersonId $subjectPersonId,
        public ?AccountId $subjectAccountId,
        public ?string $ip,
        public ?string $userAgent,
        public SecurityEventContext $context,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/D', $type) !== 1 || strlen($type) > 64) {
            throw new InvalidSecurityEvent('An event type is dotted lowercase, e.g. "authentication.failed", up to 64 characters.');
        }
        if (preg_match('/^[a-z_]{1,16}$/D', $outcome) !== 1) {
            throw new InvalidSecurityEvent('An outcome is a short lowercase word.');
        }
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidSecurityEvent('The IP address is not valid.');
        }
        if ($userAgent !== null && mb_strlen($userAgent) > 512) {
            throw new InvalidSecurityEvent('The user agent is longer than 512 characters.');
        }
    }
}
