<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\ConfirmationRequired;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\NewPersonNotSupported;
use App\Shared\Domain\PersonId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class EstablishRelationshipController
{
    public function __invoke(Request $request, EstablishRelationship $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        if ($request->exists('new_person')) {
            throw new NewPersonNotSupported;
        }
        if ($request->input('confirm_existing_person') !== true) {
            throw new ConfirmationRequired;
        }
        $person = $request->input('person_id');
        try {
            $personId = is_string($person) ? PersonId::fromString($person) : throw new InvalidArgumentException('person');
        } catch (InvalidArgumentException) {
            return RelationshipsProblems::field('invalid_relationship_query', 'person_id', 'person_id must be a Person id.');
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
        );

        return response()->json($presenter->management($view), 201);
    }
}
