<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/** Creates a tag. Needs `crm.people.manage`. "Lead" and "lead" are one tag. */
final readonly class CreateTag
{
    public function __construct(private AuthorizeAction $authorize, private ContactTagRepository $tags) {}

    /**
     * @throws AccessDenied
     * @throws InvalidContactInput
     * @throws DuplicateTag
     */
    public function __invoke(Actor $actor, string $name): TagWithCount
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        $tag = ContactTag::create(ContactTagId::generate(), $name, $actor->accountId, DateTimeImmutable::createFromInterface(now()));

        if ($this->tags->findByCanonical($tag->canonical) !== null) {
            throw new DuplicateTag;
        }
        try {
            $this->tags->add($tag);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateTag; // two creations at once: the unique index decides, and the loser gets the same answer
        }

        return new TagWithCount($tag, 0);
    }
}
