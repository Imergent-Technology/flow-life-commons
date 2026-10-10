<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\DecideDefaultRole;
use App\Modules\Relationships\Application\DefaultRoleChoice;
use App\Modules\Relationships\Application\DefaultRoleDecisionRequired;
use App\Modules\Relationships\Application\InvalidDefaultRoleChoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PUT .../default-role. Generated only for a type that has a default role (ADR 0038, K8, Part H). */
final readonly class DecideDefaultRoleController
{
    public function __invoke(Request $request, DecideDefaultRole $use, RequestActor $actors, RoutedRelationship $routed, RelationshipsPresenter $presenter): JsonResponse
    {
        if (! $request->exists('default_role')) {
            throw new DefaultRoleDecisionRequired;
        }
        try {
            $choice = DefaultRoleChoice::fromRequest($request->input('default_role'));
        } catch (InvalidDefaultRoleChoice $e) {
            return RelationshipsProblems::field('invalid_relationship_query', 'default_role', $e->getMessage());
        }

        $view = $use(
            $actors->for($request),
            $routed->type($request),
            $routed->person($request),
            RelationshipInput::instance($request->input('relationship_id')),
            RelationshipInput::wholeNumber($request->input('revision')) ?? -1,
            $choice,
        );

        return response()->json($presenter->management($view));
    }
}
