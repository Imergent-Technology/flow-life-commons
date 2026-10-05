<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CategoryRepository;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use Illuminate\Database\ConnectionInterface;

/**
 * PERMANENTLY deletes a Pack with every Card in it (ADR 0037, decisions 55 and 59). Needs `resources.manage`, and its route also
 * requires recent verification: a stolen session is not enough to destroy content. There is no Trash, Restore or Archive, and
 * nothing of the Pack is kept anywhere: unpublishing is the reversible way to take something out of use.
 *
 * Under the Category's lock (if any) and then the Pack's, in one transaction: the Cards, their narrowing rows and their asset rows,
 * the Pack's audience rows and the Pack are deleted, and the deletion is recorded as ONE `resource.pack_deleted` security event,
 * written inside the same transaction through the audit seam, so the deletion commits if and only if its event does. The event holds
 * the acting Account and ids and counts only (`pack_id`, `cards_deleted`, `files_deleted`, the last being the asset rows removed):
 * never a title, a summary, content, an address, a filename or anything that could rebuild what was deleted. The Cards it removes are
 * counted, not recorded one by one. A refused or failed deletion records no event.
 *
 * The files are removed from the store only AFTER the deletion commits (decision 61), so a rollback leaves every file with its row,
 * and a removal that fails afterwards leaves the Pack deleted and the file an orphan for the prune.
 */
final readonly class DeletePack
{
    public function __construct(
        private AuthorizeAction $authorize,
        private CategoryRepository $categories,
        private PackRepository $packs,
        private CardRepository $cards,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
        private AssetCleanup $cleanup,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     */
    public function __invoke(Actor $actor, PackId $id): void
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->database->transaction(function () use ($actor, $id): void {
            $first = $this->packs->find($id) ?? throw new PackNotFound;
            if ($first->categoryId !== null) {
                $this->categories->lock($first->categoryId);
            }
            $pack = $this->packs->lock($id) ?? throw new PackNotFound;

            $deleted = $this->cards->deleteAllOf($id);
            $this->packs->delete($pack->id);

            // Inside the transaction: the deletion and its record commit together or not at all.
            ($this->record)(
                ResourceEvent::PackDeleted->value, SecurityEventOutcome::Success, $actor, null, null, null, null,
                ['pack_id' => $pack->id->value, 'cards_deleted' => $deleted->cards, 'files_deleted' => $deleted->files()],
            );

            // Only once all of the above has committed; forgotten if it rolls back.
            $this->cleanup->afterCommit($deleted->storageKeys);
        }, 3);
    }
}
