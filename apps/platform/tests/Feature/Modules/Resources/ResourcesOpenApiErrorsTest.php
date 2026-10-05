<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * The Resources error codes the OpenAPI document promises, operation by operation (ADR 0037). OpenApiContractTest pins the routes,
 * methods, middleware and schemas; nothing there reads a response description, so a code could drift between what the application
 * answers and what the document tells a client (a Card 404 documented as `pack_not_found` while the application says `card_not_found`;
 * `unknown_category` documented nowhere). This table is the application's contract, written once, and the document must agree.
 *
 * For 404 and 409 the codes named in a response must be EXACTLY the table's, and an operation has the status only if the table lists
 * it. For 422 the description is a shared sentence naming every domain refusal, so the table's codes must each be named (a stale
 * sentence cannot lose one) without requiring the sentence to be tailored. Every code must also be one the application really
 * produces: it is looked for in the module and the exception renderers, so the table cannot drift from the code in the other direction.
 * The delivery 404 is its own code and must never name a management one (decision 46: a viewer cannot tell why).
 */

/** Every coded Resources refusal: the vocabulary a description is read against. Anything else in backticks is prose. */
const RESOURCES_ERROR_CODES = [
    'category_not_found', 'pack_not_found', 'card_not_found', 'resource_pack_not_found', 'duplicate_category', 'category_not_empty',
    'order_mismatch', 'stale_revision', 'pack_not_publishable', 'card_not_publishable', 'published_pack_requirement',
    'card_audience_conflict', 'card_limit_reached', 'card_audience_not_subset', 'invalid_resource_input', 'invalid_content',
    'invalid_uri', 'unknown_category', 'file_type_not_allowed', 'file_too_large', 'asset_unavailable', 'file_storage_unavailable',
];

/**
 * @return array<string, array<int, list<string>>> "METHOD /path" => status => the codes that response must name
 */
function resourcesErrorContract(): array
{
    $pack = '/admin/resources/packs/{pack}';
    $card = '/admin/resources/packs/{pack}/cards/{card}';
    $bothMissing = ['pack_not_found', 'card_not_found'];

    return [
        'GET /admin/resources/categories' => [],
        'POST /admin/resources/categories' => [409 => ['duplicate_category'], 422 => ['invalid_resource_input']],
        'PUT /admin/resources/categories/order' => [409 => ['order_mismatch']],
        'PATCH /admin/resources/categories/{category}' => [404 => ['category_not_found'], 409 => ['duplicate_category'], 422 => ['invalid_resource_input']],
        'DELETE /admin/resources/categories/{category}' => [404 => ['category_not_found'], 409 => ['category_not_empty']],
        'PUT /admin/resources/categories/{category}/pack-order' => [404 => ['category_not_found'], 409 => ['order_mismatch']],
        'GET /admin/resources/packs' => [],
        'POST /admin/resources/packs' => [422 => ['invalid_resource_input', 'unknown_category']],
        "GET {$pack}" => [404 => ['pack_not_found']],
        "PATCH {$pack}" => [404 => ['pack_not_found'], 409 => ['stale_revision', 'published_pack_requirement'], 422 => ['invalid_resource_input', 'unknown_category']],
        "DELETE {$pack}" => [404 => ['pack_not_found']],
        "PUT {$pack}/audiences" => [404 => ['pack_not_found'], 409 => ['published_pack_requirement', 'card_audience_conflict']],
        "POST {$pack}/publish" => [404 => ['pack_not_found'], 409 => ['pack_not_publishable']],
        "POST {$pack}/unpublish" => [404 => ['pack_not_found']],
        "GET {$pack}/preview" => [404 => ['pack_not_found']],
        "PUT {$pack}/card-order" => [404 => ['pack_not_found'], 409 => ['order_mismatch']],
        "POST {$pack}/cards" => [404 => ['pack_not_found'], 409 => ['card_limit_reached'], 413 => ['file_too_large'], 422 => ['invalid_content', 'invalid_uri', 'file_type_not_allowed'], 503 => ['file_storage_unavailable']],
        "GET {$card}" => [404 => $bothMissing],
        "PATCH {$card}" => [404 => $bothMissing, 409 => ['stale_revision', 'card_not_publishable'], 422 => ['invalid_content', 'invalid_uri']],
        "DELETE {$card}" => [404 => $bothMissing, 409 => ['published_pack_requirement']],
        "PUT {$card}/audiences" => [404 => $bothMissing, 422 => ['card_audience_not_subset']],
        "POST {$card}/publish" => [404 => $bothMissing, 409 => ['card_not_publishable']],
        "POST {$card}/unpublish" => [404 => $bothMissing, 409 => ['published_pack_requirement']],
        "POST {$card}/file" => [404 => $bothMissing, 413 => ['file_too_large'], 422 => ['file_type_not_allowed', 'invalid_resource_input'], 503 => ['file_storage_unavailable']],
        "GET {$card}/file" => [404 => [...$bothMissing, 'asset_unavailable']],
        'GET /admin/resource-library' => [],
        'GET /admin/resource-library/packs/{pack}' => [404 => ['resource_pack_not_found']],
        'GET /admin/resource-library/packs/{pack}/cards/{card}/file' => [404 => ['resource_pack_not_found', 'asset_unavailable']],
    ];
}

/**
 * The Resources operations of the document: "METHOD /path" => status => the Resources codes that response description names.
 *
 * @return array<string, array<int, list<string>>>
 */
function resourcesDocumentedErrors(): array
{
    $spec = Yaml::parseFile(base_path('openapi/openapi.yaml'));
    assert(is_array($spec) && is_array($spec['paths'] ?? null));
    $documented = [];

    foreach ($spec['paths'] as $path => $item) {
        assert(is_string($path) && is_array($item));
        if (! str_starts_with($path, '/admin/resources') && ! str_starts_with($path, '/admin/resource-library')) {
            continue;
        }
        foreach ($item as $method => $operation) {
            if (! in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            assert(is_array($operation) && is_array($operation['responses'] ?? null));
            foreach ($operation['responses'] as $status => $response) {
                assert(is_array($response));
                $description = is_string($response['description'] ?? null) ? $response['description'] : '';
                preg_match_all('/`([a-z]+(?:_[a-z]+)+)`/', $description, $found);
                $documented[strtoupper($method)." {$path}"][(int) $status] = array_values(array_intersect($found[1], RESOURCES_ERROR_CODES));
            }
        }
    }

    return $documented;
}

it('documents exactly the Resources operations the contract table lists', function () {
    expect(array_keys(resourcesDocumentedErrors()))->toEqualCanonicalizing(array_keys(resourcesErrorContract()))
        ->and(count(resourcesErrorContract()))->toBe(28);
});

it('names exactly the contract\'s codes on every 404 and 409, and has those statuses only where the contract does', function () {
    $documented = resourcesDocumentedErrors();

    foreach (resourcesErrorContract() as $operation => $statuses) {
        foreach ([404, 409, 413, 503] as $status) {
            $expected = $statuses[$status] ?? null;
            $actual = $documented[$operation][$status] ?? null;
            if ($expected === null) {
                expect($actual)->toBeNull("{$operation} documents a {$status} the contract does not have");

                continue;
            }
            expect($actual)->not->toBeNull("{$operation} does not document its {$status}");
            sort($expected);
            $named = (array) $actual;
            sort($named);
            expect($named)->toBe($expected, "{$operation} {$status}");
        }
    }
});

it('names each contract code on the 422 of the operations that can answer it', function () {
    $documented = resourcesDocumentedErrors();

    foreach (resourcesErrorContract() as $operation => $statuses) {
        foreach ($statuses[422] ?? [] as $code) {
            expect(in_array($code, $documented[$operation][422] ?? [], true))->toBeTrue("{$operation} 422 does not name {$code}");
        }
    }
});

it('pins the two drifts this table was added for: a Card 404 names card_not_found, and Pack create and edit name unknown_category', function () {
    $documented = resourcesDocumentedErrors();
    $card = '/admin/resources/packs/{pack}/cards/{card}';

    foreach (["GET {$card}", "PATCH {$card}", "DELETE {$card}", "PUT {$card}/audiences", "POST {$card}/publish", "POST {$card}/unpublish"] as $operation) {
        expect(in_array('card_not_found', $documented[$operation][404] ?? [], true))->toBeTrue("{$operation} 404 does not name card_not_found");
    }
    expect($documented['POST /admin/resources/packs'][422] ?? [])->toContain('unknown_category')
        ->and($documented['PATCH /admin/resources/packs/{pack}'][422] ?? [])->toContain('unknown_category');
});

it('never lets the delivery 404 name a management code: a viewer cannot tell why a Pack is not theirs', function () {
    $delivery = resourcesDocumentedErrors()['GET /admin/resource-library/packs/{pack}'][404] ?? [];
    $file = resourcesDocumentedErrors()['GET /admin/resource-library/packs/{pack}/cards/{card}/file'][404] ?? [];
    sort($file);

    // The file route adds only `asset_unavailable`, which it answers solely for a Card the viewer can already see.
    expect($delivery)->toBe(['resource_pack_not_found'])
        ->and($file)->toBe(['asset_unavailable', 'resource_pack_not_found']);
});

it('lists only codes the application really produces, so the table cannot drift from the code either', function () {
    $source = (string) file_get_contents(base_path('bootstrap/app.php'));
    foreach (glob(base_path('app/Modules/Resources/*/*.php')) ?: [] as $file) {
        $source .= (string) file_get_contents($file);
    }

    $used = [];
    foreach (resourcesErrorContract() as $statuses) {
        foreach ($statuses as $codes) {
            array_push($used, ...$codes);
        }
    }
    foreach (array_unique($used) as $code) {
        expect(str_contains($source, "'{$code}'"))->toBeTrue("no code in the application produces {$code}");
    }
    // The vocabulary the descriptions are read against is exactly what the table uses, so a code cannot hide outside it.
    expect(array_values(array_unique($used)))->toEqualCanonicalizing(RESOURCES_ERROR_CODES);
});
