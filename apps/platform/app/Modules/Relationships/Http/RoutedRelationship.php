<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Http;

use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipNotFound;
use App\Modules\Relationships\Domain\RelationshipType;
use App\Shared\Domain\PersonId;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The type and Person a generated route named. The type comes from the route, never from the body. */
final readonly class RoutedRelationship
{
    public function __construct(private RelationshipCatalog $catalog) {}

    public function type(Request $request): RelationshipType
    {
        $key = $request->route('relationship_type');
        $type = is_string($key) ? $this->catalog->type($key) : null;

        return $type ?? throw new RelationshipNotFound;
    }

    public function person(Request $request): PersonId
    {
        $person = $request->route('person');
        try {
            return PersonId::fromString(is_string($person) ? $person : '');
        } catch (InvalidArgumentException) {
            throw new RelationshipNotFound;
        }
    }
}
