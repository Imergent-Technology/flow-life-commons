<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Application;

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\AuthorizeAction;
use App\Modules\Crm\Application\Delegated\BasicsChanges;
use App\Modules\Crm\Application\Delegated\FieldChange;
use App\Modules\Crm\Application\Delegated\InvalidPersonBasics;
use App\Modules\Crm\Application\Delegated\PersonBasicsChanged;
use App\Modules\Crm\Application\Delegated\UnknownBasicsPerson;
use App\Modules\Crm\Application\Delegated\UpdatePersonBasics;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Relationships\Domain\RelationshipRepository;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Database\ConnectionInterface;

/**
 * Scoped editing of a Person's basic details (ADR 0038, N5, P3, P5, P6). The only Relationships use case that writes
 * People data, and it writes exactly three details: the display name, the primary email and the primary phone, each sent as
 * `{from, to}`. Nothing here can name another field, a login, a role, a Guardian relationship or any other CRM data.
 *
 * **Authorized in this order, before CRM is asked anything:**
 * 1. the type's manage capability (`403`);
 * 2. the type's `people.edit_basics` feature, otherwise the same `404` as no relationship;
 * 3. the Person holds a relationship of THIS type, in ANY state, and the request names its current id. Otherwise
 *    `404 relationship_not_found`, whether or not the Person exists, so the route cannot be used to test that. A stale
 *    instance is `409 stale_revision`. The relationship row is locked first, which holds the scope against a deletion
 *    for the whole transaction;
 * 4. only then the request's shape (`422`), so a malformed body can never reveal that a Person is out of scope;
 * 5. only then the expected values (`409 stale_person_basics`), compared by CRM and Identity after their own locks.
 *
 * The relationship's revision is not read and does not move: the details are CRM's and Identity's, and are guarded by
 * their own compare-and-set. The Person comes from the locked row, never from the request. Scope can be established by the
 * same manager through intake; that is an accepted consequence (P8), bounded by this three-field allowlist.
 */
final readonly class UpdateRelationshipPersonBasics
{
    private const array DETAILS = ['display_name', 'primary_email', 'primary_phone'];

    public function __construct(
        private AuthorizeAction $authorize,
        private RelationshipCatalog $catalog,
        private RelationshipRepository $relationships,
        private RelationshipPresentation $presentation,
        private UpdatePersonBasics $basics,
        private ConnectionInterface $database,
    ) {}

    /**
     * @param  mixed  $payload  the decoded JSON body, without the relationship id
     *
     * @throws AccessDenied
     * @throws RelationshipNotFound
     * @throws StaleRelationshipRevision
     * @throws InvalidBasicDetail
     * @throws StaleBasicDetails
     */
    public function __invoke(Actor $actor, RelationshipType $type, PersonId $person, RelationshipId $id, mixed $payload): RelationshipManagementView
    {
        $definition = $this->catalog->definition($type);
        ($this->authorize)($actor, $definition->manageCapability);
        if (! $definition->hasFeature('people.edit_basics')) {
            throw new RelationshipNotFound;
        }

        return $this->database->transaction(function () use ($actor, $type, $person, $id, $payload, $definition): RelationshipManagementView {
            $current = $this->relationships->lock($person, $type->key) ?? throw new RelationshipNotFound;
            if (! $current->id->equals($id)) {
                throw new StaleRelationshipRevision($this->presentation->management($definition, $current));
            }
            $changes = $this->changes($payload);

            try {
                ($this->basics)($current->personId, $changes, $actor);
            } catch (PersonBasicsChanged $e) {
                throw new StaleBasicDetails(new BasicDetails($e->current->displayName, $e->current->primaryEmail, $e->current->primaryPhone));
            } catch (InvalidPersonBasics $e) {
                throw new InvalidBasicDetail($e->field, $e->getMessage());
            } catch (UnknownBasicsPerson) {
                throw new RelationshipNotFound;
            }

            return $this->presentation->management($definition, $current);
        }, 3);
    }

    /**
     * The three details, or a refusal. Exactly the keys of {@see DETAILS} are accepted, at least one, each an object of
     * exactly `from` and `to`. `to` is a non-blank string. `from` is a string, or null for a contact that has no primary.
     * No message echoes a value.
     *
     * @throws InvalidBasicDetail
     */
    private function changes(mixed $payload): BasicsChanges
    {
        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw new InvalidBasicDetail('basics', 'Send an object with display_name, primary_email or primary_phone.');
        }
        foreach (array_keys($payload) as $key) {
            if (! in_array($key, self::DETAILS, true)) {
                throw new InvalidBasicDetail((string) $key, 'Only display_name, primary_email and primary_phone can be changed here.');
            }
        }
        if ($payload === []) {
            throw new InvalidBasicDetail('basics', 'Send at least one of display_name, primary_email and primary_phone.');
        }

        $changes = [];
        foreach (self::DETAILS as $detail) {
            $changes[$detail] = array_key_exists($detail, $payload) ? $this->change($detail, $payload[$detail]) : null;
        }

        return new BasicsChanges($changes['display_name'], $changes['primary_email'], $changes['primary_phone']);
    }

    private function change(string $detail, mixed $value): FieldChange
    {
        if (! is_array($value) || count($value) !== 2 || ! array_key_exists('from', $value) || ! array_key_exists('to', $value)) {
            throw new InvalidBasicDetail($detail, 'Send {"from": …, "to": …}.');
        }
        $from = $value['from'];
        $to = $value['to'];
        if (! is_string($to) || trim($to) === '') {
            throw new InvalidBasicDetail($detail, 'The new value is required and cannot be blank.');
        }
        if ($from !== null && ! is_string($from)) {
            throw new InvalidBasicDetail($detail, 'The value you last saw must be text, or null when there was none.');
        }
        if ($from === null && $detail === 'display_name') {
            throw new InvalidBasicDetail($detail, 'The name you last saw is required.');
        }

        return new FieldChange($from, $to);
    }
}
