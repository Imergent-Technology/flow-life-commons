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
 * left alone. Making a method primary demotes the one that was; clearing the flag on the primary leaves the kind with
 * no primary (the invariant is "at most one").
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

        return $this->database->transaction(function () use ($personId, $methodId, $changes): ContactMethod {
            $now = DateTimeImmutable::createFromInterface(now());
            $this->profiles->lock($personId, $now);

            $method = $this->methods->find($methodId);
            if ($method === null || ! $method->personId->equals($personId)) {
                throw new ContactMethodNotFound;
            }

            $changed = $method;
            if (array_key_exists('value', $changes)) {
                $changed = $changed->withValue($changes['value'], $now);
                if ($changed->searchValue !== $method->searchValue) {
                    foreach ($this->methods->forPerson($personId) as $other) {
                        if (! $other->id->equals($method->id) && $other->kind === $method->kind && $other->searchValue === $changed->searchValue) {
                            throw new DuplicateContactMethod;
                        }
                    }
                }
            }
            if (array_key_exists('label', $changes)) {
                $changed = $changed->withLabel($changes['label'], $now);
            }
            if (array_key_exists('is_primary', $changes) && $changes['is_primary'] !== $method->isPrimary) {
                if ($changes['is_primary']) {
                    $this->methods->clearPrimary($personId, $method->kind);
                }
                $changed = $changed->withPrimary($changes['is_primary'], $now);
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
