<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The one place a contact method is added to a Person, shared by "add a method" and "register a contact" so the rules
 * cannot drift. Internal to Crm: it does not authorize and does not open a transaction. The CALLER must hold the
 * Person's profile-row lock (ContactProfileRepository::lock), which is what makes the checks below race-free; the
 * unique indexes remain the backstop.
 */
final readonly class ContactMethodWriter
{
    public function __construct(private ContactMethodRepository $methods) {}

    /**
     * The first method of a kind a Person has becomes their primary of that kind, whatever was asked; a later one only
     * if it is asked to be, which demotes the one before it. Exactly one primary per kind, at most.
     *
     * @throws InvalidContactInput
     * @throws DuplicateContactMethod the Person already has that kind and value
     */
    public function add(PersonId $personId, NewContactMethod $new, DateTimeImmutable $now): ContactMethod
    {
        $method = ContactMethod::create(ContactMethodId::generate(), $personId, $new->kind, $new->value, $new->label, false, $now);

        $ofKind = [];
        foreach ($this->methods->forPerson($personId) as $existing) {
            if ($existing->kind !== $method->kind) {
                continue;
            }
            if ($existing->searchValue === $method->searchValue) {
                throw new DuplicateContactMethod;
            }
            $ofKind[] = $existing;
        }

        $primary = $new->isPrimary || $ofKind === [];
        if ($primary && $ofKind !== []) {
            $this->methods->clearPrimary($personId, $method->kind);
        }

        $method = $method->withPrimary($primary, $now);
        try {
            $this->methods->add($method);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateContactMethod; // the backstop: only reachable if the lock was not held
        }

        return $method;
    }
}
