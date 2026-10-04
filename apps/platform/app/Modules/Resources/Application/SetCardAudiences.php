<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\AudienceSet;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardAudience;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Sets whether a Card inherits its Pack's audiences or narrows them to a subset. Needs `resources.manage`. A Card is never
 * broader than its Pack: narrowing to an empty set, or to anything the Pack does not have, is refused `card_audience_not_subset`
 * (ADR 0037, decisions 40-41). A narrowed set is fixed: it does not grow when the Pack's does. Under the Pack's lock, so it
 * cannot race the Pack's audiences being reduced. Audiences are not an authored field, so this does not use the revision.
 */
final readonly class SetCardAudiences
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  list<Audience>  $audiences  ignored when inheriting
     *
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     * @throws InvalidResourceInput
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id, AudienceMode $mode, array $audiences): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $card = $this->database->transaction(function () use ($actor, $pack, $id, $mode, $audiences): Card {
            $locked = $this->packs->lock($pack) ?? throw new PackNotFound;
            $card = $this->cards->find($id);
            if ($card === null || ! $card->packId->equals($pack)) {
                throw new CardNotFound;
            }

            $audience = $mode === AudienceMode::Inherit
                ? CardAudience::inherit()
                : CardAudience::narrowedWithin(AudienceSet::fromList($audiences), $locked->audiences);
            $changed = $card->withAudience($audience, $actor->personId, DateTimeImmutable::createFromInterface(now()));
            $this->cards->saveAudience($changed);

            return $changed;
        }, 3);

        return $this->views->card($card);
    }
}
