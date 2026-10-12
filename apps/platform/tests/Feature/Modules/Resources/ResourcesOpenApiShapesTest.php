<?php

declare(strict_types=1);

use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Domain\Audience;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\Api;
use Tests\Support\Audiences;
use Tests\Support\ResourceFiles;
use Tests\Support\Resources;

/*
 * The served shapes against the documented schemas (ADR 0038, H): the privileged viewer's responses conform to ViewerLibrary and
 * ViewerPack, the audience reader's to ResourceLibrary and ResourcePack, and neither conforms to the other's, so the two contracts cannot be
 * swapped by accident. The checker is small on purpose: $ref, objects (required, properties, additionalProperties), arrays, oneOf, enum,
 * scalar types. Runs on MariaDB and PostgreSQL.
 */

beforeEach(function () {
    ResourceFiles::fake();
});

/** @return array<string, mixed> */
function shapesSpec(): array
{
    static $spec = null;
    $spec ??= Yaml::parseFile(base_path('openapi/openapi.yaml'));

    return Api::map($spec);
}

/**
 * Why a value does not conform to a schema, or null if it does.
 *
 * @param  array<mixed>  $schema
 */
function nonConformance(mixed $value, array $schema, string $at = '$'): ?string
{
    if (isset($schema['$ref'])) {
        $name = substr(Api::string($schema['$ref']), strlen('#/components/schemas/'));

        return nonConformance($value, Api::map(Api::map(Api::map(shapesSpec()['components'])['schemas'])[$name] ?? throw new LogicException("no schema {$name}")), $at);
    }
    if (isset($schema['allOf'])) {
        foreach (Api::rows($schema['allOf']) as $part) {
            $problem = nonConformance($value, $part, $at);
            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }
    if (isset($schema['oneOf'])) {
        $problems = [];
        foreach (Api::rows($schema['oneOf']) as $option) {
            $problem = nonConformance($value, $option, $at);
            if ($problem === null) {
                return null;
            }
            $problems[] = $problem;
        }

        return "{$at} matches no option (".implode('; ', $problems).')';
    }
    if (isset($schema['enum']) && ! in_array($value, (array) $schema['enum'], true)) {
        return "{$at} is not in the enum";
    }
    $types = isset($schema['type']) ? (array) $schema['type'] : [];
    if ($types !== []) {
        $actual = match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => 'other',
        };
        if (! in_array($actual, Api::strings($types), true) && ! ($actual === 'array' && $value === [] && in_array('object', Api::strings($types), true))) {
            return "{$at} is {$actual}, expected ".implode('|', Api::strings($types));
        }
    }
    if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
        foreach ($value as $i => $item) {
            $problem = nonConformance($item, Api::map($schema['items']), "{$at}[{$i}]");
            if ($problem !== null) {
                return $problem;
            }
        }
    }
    if (is_array($value) && ! array_is_list($value)) {
        $properties = Api::map($schema['properties'] ?? []);
        foreach (Api::strings($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $value)) {
                return "{$at} lacks {$required}";
            }
        }
        foreach ($value as $key => $inner) {
            if (! isset($properties[$key])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    return "{$at} has undocumented {$key}";
                }

                continue;
            }
            $problem = nonConformance($inner, Api::map($properties[$key]), "{$at}.{$key}");
            if ($problem !== null) {
                return $problem;
            }
        }
    }

    return null;
}

it('serves the privileged viewer\'s library and Pack exactly as ViewerLibrary and ViewerPack document them, and delivery\'s as ResourceLibrary and ResourcePack', function () {
    [$viewer] = Resources::signedInGuardian('vera.viewer@example.org', 'Vera Viewer');
    $by = Resources::editor('ed.editor@example.org', 'Ed Editor');
    $pack = Resources::pack($by, 'Handbook', Resources::category($by, 'Guides')->category->id);
    Resources::publishedCard($by, $pack, 'Words', 'Some words');
    ResourceFiles::publishedCard($by, $pack, 'Plan', 'plan.pdf');
    Resources::card($by, $pack, 'Draft card', 'Unfinished');
    app(SetPackAudiences::class)($by, $pack->pack->id, [Audience::Volunteer]);
    app(PublishPack::class)($by, $pack->pack->id);
    Resources::pack($by, 'Loose draft');
    $reader = Audiences::signIn(Audiences::reader(['volunteer']));
    $id = $pack->pack->id->value;

    $viewerLibrary = $viewer->get('/api/v1/admin/resource-library')->assertOk()->json();
    $viewerPack = $viewer->get("/api/v1/admin/resource-library/packs/{$id}")->assertOk()->json();
    $readerLibrary = $reader->get('/api/v1/admin/my-resources')->assertOk()->json();
    $readerPack = $reader->get("/api/v1/admin/my-resources/packs/{$id}")->assertOk()->json();
    $schema = fn (string $name): array => ['$ref' => "#/components/schemas/{$name}"];

    expect(nonConformance($viewerLibrary, $schema('ViewerLibrary')))->toBeNull()
        ->and(nonConformance($viewerPack, $schema('ViewerPack')))->toBeNull()
        ->and(nonConformance($readerLibrary, $schema('ResourceLibrary')))->toBeNull()
        ->and(nonConformance($readerPack, $schema('ResourcePack')))->toBeNull()
        // Not interchangeable: each body is refused by the other policy's schema.
        ->and(nonConformance($viewerLibrary, $schema('ResourceLibrary')))->not->toBeNull()
        ->and(nonConformance($viewerPack, $schema('ResourcePack')))->not->toBeNull()
        ->and(nonConformance($readerLibrary, $schema('ViewerLibrary')))->not->toBeNull()
        ->and(nonConformance($readerPack, $schema('ViewerPack')))->not->toBeNull();

    // The checker can fail: a planted extra key, a missing key and a wrong type are each seen.
    $planted = Api::map($readerPack);
    $planted['state'] = 'draft';
    $missing = $planted;
    unset($missing['state'], $missing['title']);
    $wrong = Api::map($readerPack);
    $wrong['card_count'] = 'two';
    expect(nonConformance($planted, $schema('ResourcePack')))->toContain('undocumented state')
        ->and(nonConformance($missing, $schema('ResourcePack')))->toContain('lacks title')
        ->and(nonConformance($wrong, $schema('ResourcePack')))->toContain('card_count');
});

it('documents the Uncategorized group as a null Category for the viewer, and never for a reader', function () {
    [$viewer] = Resources::signedInGuardian('vera.viewer@example.org', 'Vera Viewer');
    $by = Resources::editor('ed.editor@example.org', 'Ed Editor');
    Resources::pack($by, 'Loose draft');

    $library = Api::map($viewer->get('/api/v1/admin/resource-library')->assertOk()->json());

    expect(Api::rows($library['data'])[0]['category'])->toBeNull()
        ->and(nonConformance($library, ['$ref' => '#/components/schemas/ViewerLibrary']))->toBeNull();
});
