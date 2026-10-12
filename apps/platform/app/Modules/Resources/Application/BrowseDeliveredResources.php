<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\CategoryId;
use App\Shared\Domain\Actor;

/**
 * Policy C (ADR 0038, R11): the library of what this signed-in Person may read, found through the ONE projection rule for the
 * audiences they are eligible for now. It asks for no capability: it answers only about the Actor's own relationships, as
 * `GetCurrentMembership` does. See `AudienceDelivery`.
 */
final readonly class BrowseDeliveredResources
{
    public function __construct(private AudienceDelivery $delivery) {}

    /** @return list<DeliveredCategory> */
    public function __invoke(Actor $actor, ?CategoryId $category, ?string $search): array
    {
        return $this->delivery->library($actor, $category, $search);
    }
}
