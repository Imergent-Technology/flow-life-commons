<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Replaces a Pack's audience set. Needs `resources.manage`. Under the Pack's lock (ADR 0037, decision 57), so it is serialised
 * against narrowing a Card, publishing and unpublishing. Refused when it would
 *   - leave a PUBLISHED Pack with no audience: `published_pack_requirement`;
 *   - leave a narrowed Card wider than the new set: `card_audience_conflict`, naming those Cards (decision 41). Nothing is
 *     adjusted automatically: an automatic intersection could leave a Card visible to no one without anyone having decided that.
 * Inheriting Cards follow the Pack and need nothing. Audiences are not an authored field, so this does not use the revision.
 */
final readonly class SetPackAudiences
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<Audience>  $audiences
     *
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws PublishedPackRequirement
     * @throws CardAudienceConflict
     */
    public function __invoke(Actor $actor, PackId $id, array $audiences): ManagedPackView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $pack = $this->database->transaction(function () use ($actor, $id, $audiences): Pack {
            $pack = $this->packs->lock($id) ?? throw new PackNotFound;
            $set = AudienceSet::fromList($audiences);
            if ($pack->isPublished() && $set->isEmpty()) {
                throw new PublishedPackRequirement(Pack::NEEDS_AUDIENCE);
            }

            $conflicting = array_values(array_filter(
                $this->cards->outlinesOf($id),
                static fn (CardOutline $card): bool => $card->audience->mode === AudienceMode::Narrowed && ! $card->audience->set->isSubsetOf($set),
            ));
            if ($conflicting !== []) {
                throw new CardAudienceConflict(array_map(static fn (CardOutline $card) => $card->id, $conflicting));
            }

            if ($pack->audiences->equals($set)) {
                return $pack;
            }
            $changed = $pack->withAudiences($set, $actor->personId, DateTimeImmutable::createFromInterface(now()));
            $this->packs->saveAudiences($changed);

            return $changed;
        }, 3);

        return $this->views->pack($pack);
    }
}
