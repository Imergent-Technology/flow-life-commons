<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\Actor;

/**
 * Adds a Person to the directory with their first CRM information: Identity's `RegisterPerson` creates the Person (no
 * Account, no Membership, no access) and CRM records the profile fields and contact methods, in ONE transaction, so a
 * CRM failure leaves no Person behind.
 *
 * **Possible duplicates are advice, never a merge.** Before anything is created the server looks for Persons who share
 * an EMAIL recorded in CRM with one submitted, or whose display name is the same ignoring case, and refuses with
 * `PossibleDuplicate` and the candidates (id and display name, directory information). The caller may then register
 * again with `$confirmDistinct`, which skips only this advisory check, never validation. The check is made on the
 * server at the moment of the request, so a screen that looked a minute ago cannot make a stale all-clear.
 *
 * **What it deliberately does not check:** an Account's login email. Telling a CRM user "this address belongs to
 * Ada's Account" would let `crm.people.manage` probe data guarded by `identity.accounts.view` (ADR 0034), and CRM may
 * not read Account storage. Two Persons may also share a phone number, so a phone match is not advice either.
 */
final readonly class RegisterContact
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonRegistration $registration,
        private ReadPersonRecord $read,
    ) {}

    /**
     * @param  list<NewContactMethod>  $contactMethods
     *
     * @throws AccessDenied
     * @throws InvalidContactInput
     * @throws DuplicateContactMethod the same kind and value twice in one request
     * @throws PossibleDuplicate
     */
    public function __invoke(
        Actor $actor,
        string $displayName,
        ?string $howWeKnow,
        ?string $affiliation,
        array $contactMethods,
        bool $confirmDistinct,
    ): PersonRecord {
        ($this->authorize)($actor, Capability::ManagePeople);

        $personId = ($this->registration)($actor, $displayName, $howWeKnow, $affiliation, $contactMethods, $confirmDistinct);

        return ($this->read)($personId);
    }
}
