<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CardSummary;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Adds a Draft Card to the end of a Pack. Needs `resources.manage`. The Type is fixed here for good (ADR 0037, decision 19):
 * `basic` (content is the resource; an address is optional) or `external_link` (an address is required). The Card inherits its
 * Pack's audiences. `content` is validated against the document profile (absent means an empty document), and the summary is
 * derived from it unless one is written (`summary` given means custom).
 *
 * Under the Pack's lock, so the Card limit (100) and the position it is appended at cannot race another creation or a reorder.
 */
final readonly class CreateCard
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  array<mixed>|null  $content  a document in the Resources profile, or null for an empty one
     *
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardLimitReached
     * @throws InvalidResourceInput
     */
    public function __invoke(Actor $actor, PackId $pack, CardType $type, string $title, ?array $content, ?string $uri, ?string $summary): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $card = $this->database->transaction(function () use ($actor, $pack, $type, $title, $content, $uri, $summary): Card {
            $this->packs->lock($pack) ?? throw new PackNotFound;
            if ($this->cards->countIn($pack) >= Pack::MAX_CARDS) {
                throw new CardLimitReached;
            }

            $document = $content === null ? ContentDocument::empty() : ContentDocument::fromArray($content);
            $card = Card::draft(
                CardId::generate(), $pack, $this->cards->nextPositionIn($pack), $type, $title, $document, $uri,
                $summary === null ? CardSummary::derived($document, SummaryLength::current()) : CardSummary::custom($summary),
                $actor->personId, DateTimeImmutable::createFromInterface(now()),
            );
            $this->cards->add($card);

            return $card;
        }, 3);

        return $this->views->card($card);
    }
}
