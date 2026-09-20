<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure;

use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Inserts through the query builder rather than an Eloquent model on purpose: there is
 * then no model whose save() or delete() could be misused to rewrite history.
 */
final readonly class DatabaseSecurityEventWriter implements SecurityEventWriter
{
    public function __construct(private ConnectionInterface $database) {}

    public function append(SecurityEvent $event): void
    {
        $this->database->table('security_events')->insert([
            'id' => $event->id->value,
            'occurred_at' => $event->occurredAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'type' => $event->type,
            'actor_account_id' => $event->actorAccountId?->value,
            'subject_person_id' => $event->subjectPersonId?->value,
            'subject_account_id' => $event->subjectAccountId?->value,
            'ip' => $event->ip,
            'user_agent' => $event->userAgent,
            'outcome' => $event->outcome,
            'context' => $event->context->isEmpty() ? null : json_encode($event->context->values, JSON_THROW_ON_ERROR),
        ]);
    }
}
