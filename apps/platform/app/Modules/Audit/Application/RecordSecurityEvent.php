<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventContext;
use App\Modules\Audit\Domain\SecurityEventId;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The audit seam (ADR 0019) and the only way other modules record a security event.
 *
 * Call it synchronously, inside the same database transaction as the state change it
 * describes, so an event exists if and only if the change committed. It performs one
 * insert and propagates any failure: an unrecorded privilege change is worse than a
 * failed one.
 *
 * Never pass credentials, tokens, password hashes, session ids or CSRF values. The
 * context refuses anything shaped like one, but that is a backstop, not permission.
 */
final readonly class RecordSecurityEvent
{
    public function __construct(private SecurityEventWriter $writer) {}

    /**
     * @param  string  $type  dotted lowercase, e.g. "authentication.failed"
     * @param  array<string, scalar|null>  $context  small, flat, non-secret summary; never queried
     */
    public function __invoke(
        string $type,
        SecurityEventOutcome $outcome,
        ?Actor $actor = null,
        ?PersonId $subjectPersonId = null,
        ?AccountId $subjectAccountId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $context = [],
    ): void {
        $this->writer->append(new SecurityEvent(
            SecurityEventId::generate(),
            DateTimeImmutable::createFromInterface(now())->setTimezone(new DateTimeZone('UTC')),
            $type,
            $outcome->value,
            $actor?->accountId,
            $subjectPersonId,
            $subjectAccountId,
            $ip,
            $userAgent === null ? null : self::tidy($userAgent),
            SecurityEventContext::from($context),
        ));
    }

    /** A user agent is client-supplied text: drop control characters and bound its length. */
    private static function tidy(string $userAgent): ?string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $userAgent);
        $clean = mb_substr($clean ?? '', 0, 512);

        return $clean === '' ? null : $clean;
    }
}
