<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Modules\Crm\Application\ContactMethodWriter;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Identity\Application\PersonNameChanged;
use App\Modules\Identity\Application\PersonNotFound;
use App\Modules\Identity\Application\RenamePerson;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Compare-and-set of exactly three basic details: the display name, the primary email and the primary phone (ADR 0038, P5).
 * There is no other field to name, so there is nothing else it can write.
 *
 * **It authorizes nothing and knows nothing of relationships.** Its caller has already decided, from Relationships' own
 * table, that the Actor may edit this Person's basic details, and holds that relationship's row lock (P3, P5). No HTTP
 * class reaches it.
 *
 * **One transaction, locks in the approved order**, joining the caller's as a savepoint: the relationship row (the
 * caller's), then the `people` row inside Identity's `RenamePerson` when the name is sent, then the Person's
 * `contact_profiles` row when a contact is sent. Every `from` is compared AFTER the lock that protects it is held, as an
 * exact string, and contact methods are read with a locking read, because a plain read could serve an older MariaDB
 * snapshot than the lock just waited for. Any mismatch writes nothing, in any field, and throws
 * {@see PersonBasicsChanged} with the three current values.
 *
 * **Replacing a primary updates that method's value in place.** Setting a primary where none exists adds one. When `to`'s
 * `search_value` equals that of a NON-primary method of the same kind (a method the caller is not shown), that method is
 * promoted instead of failing or adding a duplicate: its stored value becomes the validated display form of `to`, exactly
 * what a plain replacement would store, its `search_value` and label are unchanged, and the former primary is kept as a
 * non-primary method. Nothing is deleted, and nothing in the result, the error or a later read reveals that a hidden
 * method existed. This path never answers "duplicate contact method".
 *
 * Every contact method it creates, changes, promotes or demotes carries `$by`'s Account (P9). A name change is audited by
 * Identity as `person.renamed`. It never touches an Account, a role, a profile field, a tag, a note or any other method.
 */
final readonly class UpdatePersonBasics
{
    public function __construct(
        private RenamePerson $rename,
        private FindPeople $people,
        private ContactProfileRepository $profiles,
        private ContactMethodRepository $methods,
        private ContactMethodWriter $writer,
        private ConnectionInterface $database,
    ) {}

    /**
     * @return PersonBasics the details as they are after the change
     *
     * @throws InvalidPersonBasics
     * @throws PersonBasicsChanged
     * @throws UnknownBasicsPerson
     */
    public function __invoke(PersonId $personId, BasicsChanges $changes, Actor $by): PersonBasics
    {
        $emailDisplay = $this->validated('primary_email', ContactMethodKind::Email, $changes->primaryEmail);
        $phoneDisplay = $this->validated('primary_phone', ContactMethodKind::Phone, $changes->primaryPhone);
        $name = $changes->displayName;
        if ($name !== null && $name->from === null) {
            throw new InvalidPersonBasics('display_name', 'The name you last saw is required.');
        }

        return $this->database->transaction(function () use ($personId, $changes, $by, $name, $emailDisplay, $phoneDisplay): PersonBasics {
            $now = DateTimeImmutable::createFromInterface(now());
            $storedName = null;

            if ($name !== null) {
                try {
                    $storedName = ($this->rename)($personId, $name->to, $by, $name->from)->displayName;
                } catch (PersonNameChanged $e) {
                    throw new PersonBasicsChanged($this->basics($personId, $e->currentName, null));
                } catch (PersonNotFound) {
                    throw new UnknownBasicsPerson;
                } catch (InvalidArgumentException) {
                    throw new InvalidPersonBasics('display_name', 'A name is 1 to 255 characters.');
                }
            }

            $methods = null;
            if ($changes->primaryEmail !== null || $changes->primaryPhone !== null) {
                $this->profiles->lock($personId, $now); // CRM's per-Person write lock, then decide
                $methods = $this->methods->forPersonLocked($personId);

                foreach ([[ContactMethodKind::Email, $changes->primaryEmail], [ContactMethodKind::Phone, $changes->primaryPhone]] as [$kind, $change]) {
                    if ($change !== null && $change->from !== $this->primaryOf($methods, $kind)?->value) {
                        // The name may already be renamed in this transaction, which is about to roll back: report what is stored.
                        throw new PersonBasicsChanged($this->basics($personId, $name->from ?? $storedName, $methods));
                    }
                }

                if ($emailDisplay !== null) {
                    $this->apply($personId, ContactMethodKind::Email, $emailDisplay, $methods, $now, $by);
                }
                if ($phoneDisplay !== null) {
                    $this->apply($personId, ContactMethodKind::Phone, $phoneDisplay, $methods, $now, $by);
                }
                $methods = $this->methods->forPersonLocked($personId);
            }

            return $this->basics($personId, $storedName, $methods);
        }, 3);
    }

    private function validated(string $field, ContactMethodKind $kind, ?FieldChange $change): ?string
    {
        if ($change === null) {
            return null;
        }
        try {
            return $kind->display($change->to);
        } catch (InvalidContactInput $e) {
            throw new InvalidPersonBasics($field, $e->getMessage());
        }
    }

    /**
     * Replaces, adds or promotes the primary of one kind so that its stored value is `$display`.
     *
     * @param  list<ContactMethod>  $methods  the Person's methods, read after the profile lock
     */
    private function apply(PersonId $personId, ContactMethodKind $kind, string $display, array $methods, DateTimeImmutable $now, Actor $by): void
    {
        $primary = $this->primaryOf($methods, $kind);
        if ($primary?->value === $display) {
            return; // nothing changes, and nothing is stamped
        }
        if ($primary === null) {
            $this->writer->add($personId, new NewContactMethod($kind, $display, null, true), $now, $by->accountId);

            return;
        }

        $searchValue = $kind->searchValue($display);
        if ($primary->searchValue !== $searchValue) {
            foreach ($methods as $other) {
                if ($other->kind === $kind && ! $other->isPrimary && $other->searchValue === $searchValue) {
                    // A method the caller cannot see already holds this value: promote it, and keep the old primary.
                    $this->methods->clearPrimary($personId, $kind, $by->accountId, $now);
                    $this->methods->save($other->withValue($display, $now, $by->accountId)->withPrimary(true, $now, $by->accountId));

                    return;
                }
            }
        }

        // The same mailbox or number written differently, or a different one with no hidden match: replace in place.
        $this->methods->save($primary->withValue($display, $now, $by->accountId));
    }

    /** @param  list<ContactMethod>  $methods */
    private function primaryOf(array $methods, ContactMethodKind $kind): ?ContactMethod
    {
        foreach ($methods as $method) {
            if ($method->kind === $kind && $method->isPrimary) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The three details as stored. With the Person's methods already read under the profile lock they are used; without
     * (a name was the only thing sent, or the name conflicted before any contact was touched) the primaries are read plainly,
     * and no profile row is created or locked just to answer.
     *
     * @param  list<ContactMethod>|null  $methods
     */
    private function basics(PersonId $personId, ?string $name, ?array $methods): PersonBasics
    {
        $summary = ($this->people)([$personId])[$personId->value] ?? throw new UnknownBasicsPerson;
        $name ??= $summary->displayName;

        if ($methods === null) {
            $primaries = $this->methods->primaryValues([$personId])[$personId->value] ?? ['email' => null, 'phone' => null];

            return new PersonBasics($personId, $name, $primaries['email'], $primaries['phone']);
        }

        return new PersonBasics(
            $personId,
            $name,
            $this->primaryOf($methods, ContactMethodKind::Email)?->value,
            $this->primaryOf($methods, ContactMethodKind::Phone)?->value,
        );
    }
}
