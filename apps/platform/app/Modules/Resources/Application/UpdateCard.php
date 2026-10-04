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
use App\Modules\Resources\Domain\Content\ContentDocument;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\PackId;
use App\Modules\Resources\Domain\PackRepository;
use App\Modules\Resources\Domain\SummaryMode;
use App\Shared\Domain\Actor;
use DateTimeImmutable;

/**
 * Changes a Card's authored fields: `title`, `content`, `uri` (null clears it, which an external link refuses), and the summary
 * (`summary_mode` and `summary`). Only the keys present in `$changes` change. Needs `resources.manage`; anyone who holds it may
 * edit any Card (ADR 0037, decision 54). The Type never changes.
 *
 * Summary: `summary` given writes a custom summary; `summary_mode: derived` returns to automatic and recomputes at once;
 * `summary_mode: custom` with no text keeps the stored text as the custom one. A content change recomputes a derived summary and
 * never touches a custom one.
 *
 * The edit must name the revision it was based on and is refused `stale_revision`, with the current state, if someone else's
 * edit won (decision 56). The write is conditional on that revision AND on the state the Card was validated in, so an edit
 * checked against a Draft cannot land in a Card that was Published meanwhile. An edit that would leave a PUBLISHED Card without
 * what its Type needs to be Published is refused `card_not_publishable`.
 */
final readonly class UpdateCard
{
    private const int ATTEMPTS = 3;

    public function __construct(
        private AuthorizeAction $authorize,
        private PackRepository $packs,
        private CardRepository $cards,
        private ResourceViews $views,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  any of `title`, `content`, `uri`, `summary`, `summary_mode`
     *
     * @throws AccessDenied
     * @throws PackNotFound
     * @throws CardNotFound
     * @throws StaleRevision
     * @throws InvalidResourceInput
     * @throws CardNotPublishable
     */
    public function __invoke(Actor $actor, PackId $pack, CardId $id, int $revision, array $changes): ManagedCardView
    {
        ($this->authorize)($actor, Capability::ManageResources);

        $this->packs->find($pack) ?? throw new PackNotFound;

        for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
            $card = $this->cards->find($id);
            if ($card === null || ! $card->packId->equals($pack)) {
                throw new CardNotFound;
            }
            if ($card->revision !== $revision) {
                throw new StaleRevision($this->views->card($card));
            }

            $edited = $this->apply($actor, $card, $changes);
            if ($card->isPublished() && ($unmet = $edited->unmetPublishRequirements()) !== []) {
                throw new CardNotPublishable($unmet);
            }
            if ($this->cards->saveAuthored($edited, $revision, $card->state)) {
                return $this->views->card($edited);
            }
            // The write lost: the Card changed since it was read. The loop re-reads, and either someone else's edit moved the
            // revision (stale, reported with the current state) or it was published in between (validate again, as a Published Card).
        }

        throw new StaleRevision($this->views->card($this->cards->find($id) ?? throw new CardNotFound));
    }

    /** @param  array<string, mixed>  $changes */
    private function apply(Actor $actor, Card $card, array $changes): Card
    {
        $length = SummaryLength::current();
        $content = $card->content;
        if (array_key_exists('content', $changes)) {
            $document = $changes['content'];
            if (! is_array($document)) {
                throw new InvalidResourceInput('content', 'The content is not in the allowed format: it must be a document.', InvalidResourceInput::CONTENT);
            }
            $content = ContentDocument::fromArray($document);
        }

        $summary = $card->summary->afterContentChange($content, $length);
        $mode = $changes['summary_mode'] ?? null;
        if (is_string($changes['summary'] ?? null)) {
            $summary = CardSummary::custom($changes['summary']);
        } elseif ($mode === SummaryMode::Derived->value) {
            $summary = CardSummary::derived($content, $length);
        } elseif ($mode === SummaryMode::Custom->value && $card->summary->mode === SummaryMode::Derived) {
            $summary = CardSummary::custom($card->summary->text);
        }

        $title = $changes['title'] ?? null;
        $uri = array_key_exists('uri', $changes) ? (is_string($changes['uri']) ? $changes['uri'] : null) : $card->externalUri;

        return $card->edited(is_string($title) ? $title : $card->title, $summary, $content, $uri, $actor->personId, DateTimeImmutable::createFromInterface(now()));
    }
}
