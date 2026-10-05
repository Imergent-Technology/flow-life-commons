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
use App\Modules\Resources\Domain\FileStoreFailure;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\Pack;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\ResourceAsset;
use App\Modules\Resources\Domain\ResourceText;
use App\Shared\Domain\Actor;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Adds a Draft Card to the end of a Pack. Needs `resources.manage`. The Type is fixed here for good (ADR 0037, decision 19):
 * `basic` (content is the resource; an address is optional), `external_link` (an address is required) or `file` (its file is
 * required, and comes with this request; there is no address). The Card inherits its Pack's audiences. `content` is validated against
 * the document profile (absent means an empty document), and the summary is derived from it unless one is written (`summary` given
 * means custom).
 *
 * Under the Pack's lock, so the Card limit (100) and the position it is appended at cannot race another creation or a reorder.
 *
 * A File Card's file is written BEFORE that transaction, and is referenced only once the transaction commits (decision 61), so no
 * committed Card can ever name a file that was not written. Everything that can refuse without the lock (the Pack, the limit, the
 * title, the document, the file itself) is checked first, so a refused creation normally writes nothing; if the transaction then
 * fails, the file just written is removed again, and if even that fails it is an unreferenced orphan for the prune.
 */
final readonly class CreateCard
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
     * @param  array<mixed>|null  $content  a document in the Resources profile, or null for an empty one
     * @param  IncomingFile|null  $file  the file of a `file` Card, and only of one
     *
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardLimitReached
     * @throws InvalidResourceInput
     * @throws FileTooLarge
     * @throws FileTypeNotAllowed
     * @throws FileStoreFailure
     */
    public function __invoke(Actor $actor, PackId $pack, CardType $type, string $title, ?array $content, ?string $uri, ?string $summary, ?IncomingFile $file = null): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        if (($type === CardType::File) !== ($file !== null)) {
            throw new InvalidResourceInput('file', $type === CardType::File ? 'A file card needs its file.' : 'Only a file card holds a file.');
        }

        $now = DateTimeImmutable::createFromInterface(now());

        $asset = null;
        if ($file !== null) {
            // In the order the transaction would refuse them, so the answer is the same; only then is anything written.
            $this->packs->find($pack) ?? throw new PackNotFound;
            if ($this->cards->countIn($pack) >= Pack::MAX_CARDS) {
                throw new CardLimitReached;
            }
            self::summaryOf(self::documentOf($content), $summary);
            ResourceText::singleLine($title, 'title', 'Card', ResourceText::TITLE_MAX);
            Card::addressFor($type, $uri);
            $asset = $this->intake->accept($file, $actor->personId, $now);
        }

        try {
            $card = $this->database->transaction(function () use ($actor, $pack, $type, $title, $content, $uri, $summary, $asset, $now): Card {
                $this->packs->lock($pack) ?? throw new PackNotFound;
                if ($this->cards->countIn($pack) >= Pack::MAX_CARDS) {
                    throw new CardLimitReached;
                }

                $document = self::documentOf($content);
                $card = Card::draft(
                    CardId::generate(), $pack, $this->cards->nextPositionIn($pack), $type, $title, $document, $uri, $asset,
                    self::summaryOf($document, $summary), $actor->personId, $now,
                );
                $this->cards->add($card);

                return $card;
            }, 3);
        } catch (Throwable $e) {
            if ($asset instanceof ResourceAsset) {
                $this->cleanup->discard($asset->storageKey);
            }

            throw $e;
        }

        return $this->views->card($card);
    }

    /**
     * @param  array<mixed>|null  $content
     *
     * @throws InvalidResourceInput
     */
    private static function documentOf(?array $content): ContentDocument
    {
        return $content === null ? ContentDocument::empty() : ContentDocument::fromArray($content);
    }

    /** @throws InvalidResourceInput */
    private static function summaryOf(ContentDocument $document, ?string $summary): CardSummary
    {
        return $summary === null ? CardSummary::derived($document, SummaryLength::current()) : CardSummary::custom($summary);
    }
}
