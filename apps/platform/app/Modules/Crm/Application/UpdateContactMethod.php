<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\PersonExists;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Changes a contact method's value, label or primary flag. Needs `crm.people.manage`. A field not in `$changes` is
 * left alone. Making a method primary demotes the one that was, atomically. A Person with any method of a kind has exactly
 * ONE primary of it, so the current primary cannot simply be un-set: make another method primary instead.
 */
final readonly class UpdateContactMethod
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PersonExists $personExists,
        private ContactProfileRepository $profiles,
        private ContactMethodRepository $methods,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  array{value?: string, label?: ?string, is_primary?: bool}  $changes
     *
     * @throws AccessDenied
     * @throws UnknownPerson
     * @throws ContactMethodNotFound the method does not exist, or belongs to another Person
     * @throws InvalidContactInput
     * @throws DuplicateContactMethod the Person already has that kind and value
     */
    public function __invoke(Actor $actor, PersonId $personId, ContactMethodId $methodId, array $changes): ContactMethod
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        if (! ($this->personExists)($personId)) {
            throw new UnknownPerson;
        }

        return $this->database->transaction(function () use ($actor, $personId, $methodId, $changes): ContactMethod {
            $now = DateTimeImmutable::createFromInterface(now());
            $this->profiles->lock($personId, $now);

            $method = $this->methods->find($methodId);
            if ($method === null || ! $method->personId->equals($personId)) {
                throw new ContactMethodNotFound;
            }

            // A field that comes out the same changes nothing and stamps nothing: only a row that really changes carries
            // the acting Account (ADR 0038, P9).
            $changed = $method;
            if (array_key_exists('value', $changes)) {
                $candidate = $method->withValue($changes['value'], $now, $actor->accountId);
                if ($candidate->value !== $method->value) {
                    $changed = $candidate;
                    if ($changed->searchValue !== $method->searchValue) {
                        foreach ($this->methods->forPerson($personId) as $other) {
                            if (! $other->id->equals($method->id) && $other->kind === $method->kind && $other->searchValue === $changed->searchValue) {
                                throw new DuplicateContactMethod;
                            }
                        }
                    }
                }
            }
            if (array_key_exists('label', $changes)) {
                $candidate = $changed->withLabel($changes['label'], $now, $actor->accountId);
                if ($candidate->label !== $changed->label) {
                    $changed = $candidate;
                }
            }
            if (array_key_exists('is_primary', $changes) && $changes['is_primary'] !== $method->isPrimary) {
                if (! $changes['is_primary']) {
                    // Exactly one primary per kind while any method of the kind exists: promoting another is the only way to demote this one.
                    throw new InvalidContactInput('is_primary', 'Make another contact method primary instead.');
                }
                $this->methods->clearPrimary($personId, $method->kind, $actor->accountId, $now);
                $changed = $changed->withPrimary(true, $now, $actor->accountId);
            }
            if ($changed === $method) {
                return $method;
            }

            try {
                $this->methods->save($changed);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicateContactMethod; // the backstop: only reachable if the lock was not held
            }

            return $changed;
        }, 3);
    }
}
