<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvalidEmailAddress;
use App\Modules\Identity\Domain\Person;
use App\Shared\Domain\PersonId;
use InvalidArgumentException;

/**
 * Who is being invited, validated by Identity's own rules (the ASCII-only canonical email and the
 * thin Person's display name). It lets another module hand Identity plain strings and get back a
 * value that is already known to be acceptable, without ever naming an Identity Domain type.
 */
final readonly class InvitationDetails
{
    private function __construct(
        private EmailAddress $email,
        private string $displayName,
    ) {}

    /**
     * @throws InvalidInvitationDetails
     */
    public static function from(string $email, string $displayName): self
    {
        try {
            $address = EmailAddress::fromString($email);
            // Person owns the display-name rules; building one is how they are checked.
            $name = Person::create(PersonId::generate(), $displayName, new \DateTimeImmutable)->displayName;
        } catch (InvalidEmailAddress) {
            throw new InvalidInvitationDetails('That is not a valid email address (printable ASCII; internationalised domains as punycode).');
        } catch (InvalidArgumentException) {
            throw new InvalidInvitationDetails('A display name must be 1 to '.Person::MAX_DISPLAY_NAME_LENGTH.' characters.', 'display_name');
        }

        return new self($address, $name);
    }

    public function email(): EmailAddress
    {
        return $this->email;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    /** The address as it will be matched: lowercase, for showing back to an operator. */
    public function canonicalEmail(): string
    {
        return $this->email->canonical;
    }
}
