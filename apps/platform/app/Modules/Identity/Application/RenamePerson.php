<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Corrects a Person's display name. Identity owns the Person, so this is the only way a name changes; a module
 * that enriches People (CRM) reaches it through its own authorized use case and never writes `people`.
 *
 * The id, the creation time and everything that hangs off the Person (its Account, Membership, role assignments)
 * are untouched: they reference `person_id`, which does not move. The name is validated exactly as a new Person's
 * is (trimmed, 1 to 255 characters).
 *
 * One transaction: the Person is re-read WITH a lock, so two corrections at once are applied in order and the
 * event describes the change that really happened; the save and `person.renamed` commit together (ADR 0019). A name
 * that is already the current one changes nothing and records nothing.
 *
 * **Audit carries who, whom and when, and deliberately not the names.** `security_events` is append-only and
 * survives its subjects; a copy of a personal name there could never be corrected or anonymised with the Person
 * (docs/architecture/data-ownership.md, "Still open"). The name lives on the Person alone.
 *
 * **Authorization and step-up are the caller's business, and step-up is not required** (ADR 0034): a display-name
 * correction grants and removes no authority. Identity cannot ask Access what a caller may do, and must not know
 * which capability a future adapter checks. `$by` is recorded for the audit trail only; null means the platform or
 * an operator with server access. No adapter exists yet.
 */
final readonly class RenamePerson
{
    public function __construct(
        private PersonRepository $people,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws PersonNotFound
     * @throws \InvalidArgumentException the name is empty or too long (Person's own rule)
     */
    public function __invoke(PersonId $personId, string $displayName, ?Actor $by = null): PersonSummary
    {
        return $this->database->transaction(function () use ($personId, $displayName, $by): PersonSummary {
            $current = $this->people->findForUpdate($personId) ?? throw new PersonNotFound;

            $renamed = $current->rename($displayName, DateTimeImmutable::createFromInterface(now()));

            if ($renamed->displayName !== $current->displayName) {
                $this->people->save($renamed);

                ($this->record)(
                    IdentityEvent::PersonRenamed->value, SecurityEventOutcome::Success,
                    $by, $current->id, null, null, null,
                    ['changed' => 'display_name'],
                );
            }

            return new PersonSummary($renamed->id, $renamed->displayName);
        }, 3);
    }
}
