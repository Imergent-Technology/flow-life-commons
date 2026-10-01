<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\ContactTagRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Shared\Domain\Actor;
use Illuminate\Database\UniqueConstraintViolationException;

/** Renames a tag; People who hold it keep it. Needs `crm.people.manage`. Changing only the case of a name is allowed. */
final readonly class RenameTag
{
    public function __construct(private AuthorizeAction $authorize, private ContactTagRepository $tags) {}

    /**
     * @throws AccessDenied
     * @throws TagNotFound
     * @throws InvalidContactInput
     * @throws DuplicateTag another tag already has that name
     */
    public function __invoke(Actor $actor, ContactTagId $tagId, string $name): TagWithCount
    {
        ($this->authorize)($actor, Capability::ManagePeople);

        $tag = $this->tags->find($tagId) ?? throw new TagNotFound;
        $renamed = $tag->renamed($name);

        $other = $this->tags->findByCanonical($renamed->canonical);
        if ($other !== null && ! $other->id->equals($tag->id)) {
            throw new DuplicateTag;
        }
        try {
            $this->tags->save($renamed);
        } catch (UniqueConstraintViolationException) {
            throw new DuplicateTag;
        }

        return new TagWithCount($renamed, $this->tags->assignmentCount($tagId));
    }
}
