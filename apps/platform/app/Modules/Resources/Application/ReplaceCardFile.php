<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Access\Application\Capability;
use App\Modules\Resources\Domain\Card;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardRepository;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\FileStoreFailure;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Replaces a File Card's file (ADR 0037, decision 65). Needs `resources.manage`, and nothing more: replacing a file is routine and
 * is not audited (decisions 52, 55). The Card keeps its identity, state, audiences, position and revision; only its file changes.
 *
 * The sequence, which no failure can leave with a Card naming a file that is not there:
 *
 * 1. The new file is judged and written under a NEW key (`AssetIntake`); the old file is untouched. A refused or failed upload
 *    changes nothing.
 * 2. One transaction under the Pack's lock, then the Card's row (decision 57): the new asset row is inserted, the Card points at it,
 *    and the old asset row is deleted. Until this commits, the old file is the Card's file and is served.
 * 3. After the commit, and only then, the old file is removed. If that fails, the Card is still correct and the old file is an
 *    orphan for the prune. If the transaction fails instead (the Card was deleted meanwhile, say), the NEW file is removed again.
 *
 * Replacement does not use the revision (decision 56): it is not an authored edit, and two replacements of one Card serialise on the
 * Pack's lock, the later one winning, each removing the file it replaced. Neither leaves a file behind that a row refers to.
 */
final readonly class ReplaceCardFile
{
    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
        private ConnectionInterface $database,
        private AssetIntake $intake,
        private AssetCleanup $cleanup,
    ) {}

    /**
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     * @throws InvalidResourceInput
     * @throws FileTooLarge
     * @throws FileTypeNotAllowed
     * @throws FileStoreFailure
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id, IncomingFile $file): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        // What can be refused without the lock is refused before anything is written. All of it is checked again under the lock.
        $this->packs->find($pack) ?? throw new PackNotFound;
        self::fileCardIn($this->cards->find($id), $pack);

        $now = DateTimeImmutable::createFromInterface(now());
        $asset = $this->intake->accept($file, $actor->personId, $now);

        try {
            $replaced = $this->database->transaction(function () use ($actor, $pack, $id, $asset, $now): Card {
                $this->packs->lock($pack) ?? throw new PackNotFound;
                $card = self::fileCardIn($this->cards->lock($id), $pack);

                $previous = $card->asset;
                $changed = $card->withAsset($asset, $actor->personId, $now);
                $this->cards->replaceAsset($changed, $previous?->id);
                // Registered inside the transaction: it runs only if this commits, and is forgotten if it rolls back or retries.
                $this->cleanup->afterCommit($previous === null ? [] : [$previous->storageKey]);

                return $changed;
            }, 3);
        } catch (Throwable $e) {
            $this->cleanup->discard($asset->storageKey);

            throw $e;
        }

        return $this->views->card($replaced);
    }

    /**
     * @throws CardNotFound
     * @throws InvalidResourceInput
     */
    private static function fileCardIn(?Card $card, PackId $pack): Card
    {
        if ($card === null || ! $card->packId->equals($pack)) {
            throw new CardNotFound;
        }
        if ($card->type !== CardType::File) {
            throw new InvalidResourceInput('file', 'Only a file card holds a file.');
        }

        return $card;
    }
}
