<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Crm\Application\Delegated\InvalidPersonBasics;
use App\Modules\Crm\Application\Delegated\PossibleDuplicatePerson;
use App\Modules\Crm\Application\Delegated\RegisterMinimalPerson;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Relationships\Domain\FieldValues;
use App\Modules\Relationships\Domain\InvalidRelationshipField;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRecord;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Modules\Relationships\Domain\StatusChange;
use App\Modules\Relationships\Domain\UnknownRelationshipField;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Records a relationship (ADR 0038, F9, P3, P7, K7). One capability: the type's manage capability, from the definition.
 *
 * **Two ways in, never both.** An existing Person, named by id and deliberately confirmed (`confirm_existing_person`),
 * is attached and left exactly as they are. A new Person, where the type enables `people.create_person`, is registered
 * through CRM's delegated `RegisterMinimalPerson` in the same transaction: the Person, their primary methods, the
 * relationship and its creation history row commit together or not at all. Nothing is merged or adopted, and the
 * duplicate advice is CRM's, advisory and not race-proof. No Account, Membership or role is created either way.
 *
 * A type with a default role requires an explicit decision when the initial state qualifies, and
 * refuses one when it does not. The grant's Person is read back from the locked row.
 */
final readonly class EstablishRelationship
{
    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private FindPeople $people,
        private RelationshipPresentation $presentation,
        private ApplyDefaultRoleDecision $decisions,
        private RegisterMinimalPerson $registerPerson,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  array<mixed, mixed>  $fields
     *
     * @throws AccessDenied
     * @throws ConfirmationRequired
     * @throws DefaultRoleNotApplicable
     * @throws DefaultRoleDecisionRequired
     * @throws InvalidDefaultRoleChoice
     * @throws RoleProvisioningNotPermitted
     * @throws TransitionNotAllowed
     * @throws UnknownRelationshipField
     * @throws InvalidRelationshipField
     * @throws UnknownRelationshipPerson
     * @throws RelationshipExists
     * @throws NewPersonNotSupported
     * @throws InvalidBasicDetail
     * @throws PossibleDuplicatePersonAdvice
     */
    public function __invoke(
        Actor $actor,
        RelationshipType $type,
        ?PersonId $person,
        bool $confirmExistingPerson,
        string $status,
        array $fields,
        bool $defaultRoleSent,
        mixed $defaultRole = null,
        ?NewPersonDetails $newPerson = null,
    ): RelationshipManagementView {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);

        if ($newPerson !== null) {
            if ($person !== null) {
                throw new InvalidBasicDetail('person_id', 'Name an existing Person or a new one, not both.');
            }
            if (! $definition->hasFeature('people.create_person')) {
                throw new NewPersonNotSupported;
            }
        } elseif ($person === null) {
            throw new InvalidBasicDetail('person_id', 'Name an existing Person or a new one.');
        } elseif (! $confirmExistingPerson) {
            throw new ConfirmationRequired;
        }
        $choice = DefaultRoleRequest::choice($defaultRoleSent, $defaultRole);
        if (! $definition->isInitial($status)) {
            throw new TransitionNotAllowed;
        }
        DefaultRoleRequest::guard($definition, $status, $choice);
        if ($person !== null && ($this->people)([$person]) === []) {
            throw new UnknownRelationshipPerson;
        }

        $now = RelationshipTime::now();
        $parsed = FieldValues::parse($definition, $fields, $now);
        $stored = [];
        foreach ($parsed as $key => $value) {
            if ($value === null) {
                throw new InvalidRelationshipField($key);
            }
            $stored[$key] = $value;
        }
        $missing = FieldValues::missingRequired($definition, $stored);
        if ($missing !== null) {
            throw new InvalidRelationshipField($missing);
        }

        try {
            // A missing-row lock under repeatable read covers the gap beside that key, so recognizing
            // a Guardian would block recording a Volunteer for the same Person. Read committed locks
            // only a row that exists; the unique index still serializes two intakes of one type.
            if ($this->database->transactionLevel() === 0 && $this->driver() !== 'pgsql') {
                $this->database->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }

            return $this->database->transaction(function () use ($actor, $type, $person, $newPerson, $status, $stored, $now, $definition, $choice): RelationshipManagementView {
                if ($this->driver() === 'pgsql' && $this->database->transactionLevel() === 1) {
                    $this->database->statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                }
                if ($newPerson !== null) {
                    // Inside this transaction: a failure after this point removes the Person with the relationship.
                    try {
                        $person = ($this->registerPerson)($newPerson->displayName, $newPerson->email, $newPerson->phone, $newPerson->confirmDistinct, $actor)->id;
                    } catch (InvalidPersonBasics $e) {
                        throw new InvalidBasicDetail('new_person.'.$e->field, $e->getMessage());
                    } catch (PossibleDuplicatePerson $e) {
                        throw new PossibleDuplicatePersonAdvice(array_map(
                            static fn ($advice): PersonAdvice => new PersonAdvice($advice->id, $advice->displayName, $advice->matchedOn),
                            $e->candidates,
                        ));
                    }
                }
                if ($this->relationships->lock($person, $type->key) !== null) {
                    throw new RelationshipExists;
                }
                $record = new RelationshipRecord(
                    RelationshipId::generate(), $person, $type->key, $status, 1,
                    $now, $actor->personId, $now, $actor->personId, $now, $actor->personId,
                    $stored, [new StatusChange(null, $status, $now, $actor->personId)],
                );
                $this->relationships->add($record);
                $locked = $this->relationships->lock($person, $type->key) ?? throw new RelationshipExists;
                if ($choice !== null && $definition->defaultRole !== null) {
                    $outcome = ($this->decisions)($actor, $definition->defaultRole, $locked, $choice, $definition->qualifies($status));
                    $locked = $locked->withProvisions($outcome->provisions);
                }

                return $this->presentation->management($definition, $locked);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw new RelationshipExists;
        }
    }

    private function driver(): string
    {
        $connection = config('database.default');
        if (! is_string($connection)) {
            return '';
        }
        $driver = config("database.connections.{$connection}.driver");

        return is_string($driver) ? $driver : '';
    }
}
