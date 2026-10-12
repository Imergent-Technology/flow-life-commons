<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\PersonRegistration;
use App\Modules\Crm\Application\PossibleDuplicate;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\Actor;

/**
 * Registers a new Person for a relationship's intake, with at most one email and one phone, each primary (ADR 0038, P1, P7).
 * It shares CRM's `RegisterContact` implementation, so the duplicate advice, the normalization and the provenance are the same
 * code. Every method it creates is stamped with `$by`'s Account (P9).
 *
 * It creates no Account, no Membership and no role: Identity's `RegisterPerson` creates a bare Person. It authorizes nothing,
 * and joins the caller's transaction as a savepoint, so a relationship's intake commits or rolls back with the Person.
 */
final readonly class RegisterMinimalPerson
{
    public function __construct(private PersonRegistration $registration) {}

    /**
     * @throws InvalidPersonBasics
     * @throws PossibleDuplicatePerson
     */
    public function __invoke(string $displayName, ?string $email, ?string $phone, bool $confirmDistinct, Actor $by): RegisteredPerson
    {
        $methods = [];
        foreach (['email' => [ContactMethodKind::Email, $email], 'phone' => [ContactMethodKind::Phone, $phone]] as $field => [$kind, $value]) {
            if ($value === null) {
                continue;
            }
            try {
                $kind->display($value);
            } catch (InvalidContactInput $e) {
                throw new InvalidPersonBasics($field, $e->getMessage());
            }
            $methods[] = new NewContactMethod($kind, $value, null, true);
        }

        try {
            $id = ($this->registration)($by, $displayName, null, null, $methods, $confirmDistinct);
        } catch (InvalidContactInput $e) {
            throw new InvalidPersonBasics($e->field, $e->getMessage());
        } catch (PossibleDuplicate $e) {
            throw new PossibleDuplicatePerson(array_map(
                static fn ($candidate): DuplicatePersonAdvice => new DuplicatePersonAdvice($candidate->person->id, $candidate->person->displayName, $candidate->matchedOn),
                $e->candidates,
            ));
        }

        return new RegisteredPerson($id, trim($displayName));
    }
}
