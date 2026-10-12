<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\ConfirmationRequired;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\NewPersonDetails;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class EstablishRelationshipController
{
    public function __invoke(Request $request, EstablishRelationship $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        $newPerson = null;
        $personId = null;
        if ($request->exists('new_person')) {
            if ($request->exists('person_id')) {
                return RelationshipsProblems::field('invalid_relationship_query', 'person_id', 'Name an existing Person or a new one, not both.');
            }
            $newPerson = $this->newPerson($request);
            if (! $newPerson instanceof NewPersonDetails) {
                return $newPerson;
            }
        } else {
            if ($request->input('confirm_existing_person') !== true) {
                throw new ConfirmationRequired;
            }
            $person = $request->input('person_id');
            try {
                $personId = is_string($person) ? PersonId::fromString($person) : throw new InvalidArgumentException('person');
            } catch (InvalidArgumentException) {
                return RelationshipsProblems::field('invalid_relationship_query', 'person_id', 'person_id must be a Person id.');
            }
        }
        $status = $request->input('status');
        if (! is_string($status)) {
            return RelationshipsProblems::field('invalid_relationship_query', 'status', 'status must be a string.');
        }
        $fields = RelationshipInput::fields($request);
        if (! is_array($fields)) {
            return RelationshipsProblems::field('invalid_relationship_query', 'fields', 'fields must be an object.');
        }

        $view = $use(
            $actors->for($request),
            $routed->type($request),
            $personId,
            true,
            $status,
            $fields,
            $request->exists('default_role'),
            $request->input('default_role'),
            $newPerson,
        );

        return response()->json($presenter->management($view), 201);
    }

    /**
     * The `new_person` object: a display name, and optionally one email and one phone. Read from the raw body so an empty
     * string stays an empty string and is refused, not silently dropped. Anything else in it is refused.
     */
    private function newPerson(Request $request): NewPersonDetails|JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $given = is_array($payload) ? ($payload['new_person'] ?? null) : null;
        if (! is_array($given) || ($given !== [] && array_is_list($given))) {
            return RelationshipsProblems::field('invalid_person_basics', 'new_person', 'new_person must be an object.');
        }
        foreach (array_keys($given) as $key) {
            if (! in_array($key, ['display_name', 'email', 'phone'], true)) {
                return RelationshipsProblems::field('invalid_person_basics', 'new_person.'.$key, 'Only display_name, email and phone can be recorded for a new Person.');
            }
        }
        $name = $given['display_name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return RelationshipsProblems::field('invalid_person_basics', 'new_person.display_name', 'A name is 1 to 255 characters.');
        }
        foreach (['email', 'phone'] as $contact) {
            if (array_key_exists($contact, $given) && $given[$contact] !== null && ! is_string($given[$contact])) {
                return RelationshipsProblems::field('invalid_person_basics', 'new_person.'.$contact, 'Send text, or leave it out.');
            }
        }
        $distinct = $payload['confirm_distinct'] ?? false;
        if (! is_bool($distinct)) {
            return RelationshipsProblems::field('invalid_relationship_query', 'confirm_distinct', 'confirm_distinct must be true or false.');
        }

        return new NewPersonDetails(
            $name,
            isset($given['email']) ? $given['email'] : null,
            isset($given['phone']) ? $given['phone'] : null,
            $distinct,
        );
    }
}
