<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Person;
use App\Modules\Identity\Domain\PersonRepository;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;

/**
 * Registers a Person who has no Account: the identity anchor for a human this platform
 * needs to know before, or entirely without, them ever signing in (ADR 0015). Creates
 * a Person and nothing else — no Account, no invitation, no role assignment, no security
 * event.
 *
 * Until now the only path that created a Person was `InviteAccount`, which necessarily
 * creates an Account alongside it. This is the path for a Person with none, first needed
 * by the Membership Foundation (ADR 0028): a member is registered before, and sometimes
 * without ever, holding a Console login.
 *
 * **Provenance is not this use case's concern.** Why a Person was registered — which
 * business operation caused it, which membership source, which operator acted — belongs
 * entirely to the caller. A future Membership grant records its own provenance
 * (`granted_by_account_id`); this class has no equivalent field and asks for none.
 *
 * **No security event is recorded.** ADR 0019 built `security_events` as the seam for
 * identity- and access-relevant occurrences, not a general business audit trail, and there
 * is no existing precedent for auditing bare Person creation. A future caller that grants
 * something alongside registration (Membership, for one) is responsible for auditing what
 * IT does.
 *
 * **Performs exactly one write**, so it opens no transaction of its own. A caller that
 * needs registration to commit or roll back atomically with its own write — the
 * Membership Foundation's "register a Person and grant membership access" — wraps both in
 * one transaction, the way `Access\Application\BootstrapAdministrator` already wraps its
 * call to `InviteAccount` in one.
 */
final readonly class RegisterPerson
{
    public function __construct(private PersonRepository $people) {}

    /**
     * @throws \InvalidArgumentException the display name is empty or too long (Person's own rule)
     */
    public function __invoke(string $displayName): Person
    {
        $person = Person::create(PersonId::generate(), $displayName, DateTimeImmutable::createFromInterface(now()));

        $this->people->save($person);

        return $person;
    }
}
