<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\DeleteRelationship;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class DeleteRelationshipController
{
    public function __invoke(Request $request, DeleteRelationship $use, RequestActor $actors, RoutedRelationship $routed): Response
    {
        $use(
            $actors->for($request),
            $routed->type($request),
            $routed->person($request),
            RelationshipInput::instance($request->query('relationship_id')),
            RelationshipInput::wholeNumber($request->query('revision')) ?? -1,
        );

        return response()->noContent();
    }
}
