<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardOutline;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * PERMANENTLY deletes a Card (ADR 0037, decisions 55 and 58). Needs `resources.manage`, and its route also requires recent
 * verification. There is no Trash, Restore or Archive: unpublishing is the reversible way to take a Card out of use.
 *
 * Under the Pack's lock, in one transaction: refused `published_pack_requirement` if it is the last Published Card of a
 * Published Pack; otherwise the Card and its narrowing rows are deleted and ONE `resource.card_deleted` security event is
 * recorded inside the same transaction through the audit seam, so the deletion commits if and only if its event does. The event
 * holds the acting Account and ids only (`card_id`, `pack_id`, `card_type`): never a title, summary, content, address or
 * anything that could rebuild the Card. A refused or failed deletion records no event.
 */
final readonly class DeleteCard
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     * @throws PublishedPackRequirement
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id): void
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->database->transaction(function () use ($actor, $pack, $id): void {
            $locked = $this->packs->lock($pack) ?? throw new PackNotFound;
            $card = $this->cards->find($id);
            if ($card === null || ! $card->packId->equals($pack)) {
                throw new CardNotFound;
            }
            if ($locked->isPublished() && $card->isPublished()
                && count(array_filter($this->cards->outlinesOf($pack), static fn (CardOutline $o): bool => $o->isPublished())) === 1) {
                throw new PublishedPackRequirement(Pack::NEEDS_PUBLISHED_CARD);
            }

            $this->cards->delete($id);

            // Inside the transaction: the deletion and its record commit together or not at all.
            ($this->record)(
                ResourceEvent::CardDeleted->value, SecurityEventOutcome::Success, $actor, null, null, null, null,
                ['card_id' => $card->id->value, 'pack_id' => $card->packId->value, 'card_type' => $card->type->value],
            );
        }, 3);
    }
}
