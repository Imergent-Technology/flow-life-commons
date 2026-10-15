<?php

declare(strict_types=1);

use App\Modules\Access\Application\Authorizer;
use App\Modules\Access\Application\Capability;
use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Access\Application\Role;
use App\Modules\Relationships\Application\DefinitionSource;
use App\Modules\Relationships\Application\DescribeRelationshipTypes;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\RelationshipTypeView;
use App\Shared\Domain\Actor;
use Tests\Support\Access;
use Tests\Support\Identity;

/*
 * ADR 0038, Part K and the A-rows "provisioning hint never authorizes". The hint (`actions.provision_default_role`) is true only when
 * the Actor may manage the type AND Access would let them assign roles AND the type names a default role. No production type and no
 * role gives the middle one apart from the others: the only type with a default role is Guardian, whose manage capability only the
 * platform administrator holds, and the administrator holds every capability. So the conjunct `$mayAssignRoles` is reached here through
 * the one real seam a test has, a definition: a test-only type that borrows the Volunteer capability pair, which the Guardian role
 * does hold (it manages Volunteers and assigns no role), and names a default role. The production Volunteer document is left out of
 * this catalog, because no capability may serve two types.
 *
 * Both Actors are real: roles from Access's catalog, decided by the real Authorizer. Nothing is faked.
 */

/** A test-only type through the real definition source: the Volunteer pair, and Guardian Initiate as its default role. */
function stewardCatalog(bool $withDefaultRole = true): RelationshipCatalog
{
    $document = require dirname(__DIR__, 4).'/app/Modules/Relationships/Definitions/volunteer.php';
    assert(is_array($document));
    /** @var array<string, mixed> $document */
    $document['type'] = 'steward';
    $document['slug'] = 'stewards';
    $document['default_role'] = $withDefaultRole ? ProvisionableRole::GuardianInitiate : null;
    $document['verification'] = $withDefaultRole ? ['intake', 'status', 'default_role', 'delete'] : ['delete'];

    return RelationshipCatalog::load([new class($document) implements DefinitionSource
    {
        /** @param  array<string, mixed>  $document */
        public function __construct(private array $document) {}

        public function documents(): array
        {
            return [$this->document];
        }
    }]);
}

/** @return array{view: bool, manage: bool, delete: bool, hint: bool} what the Actor is served for the steward type */
function stewardActions(RelationshipCatalog $catalog, Actor $actor): array
{
    $views = (new DescribeRelationshipTypes($catalog, app(Authorizer::class)))($actor);
    $served = array_values(array_filter($views, fn (RelationshipTypeView $view): bool => $view->definition->type->key === 'steward'));
    expect($served)->toHaveCount(1);

    return [
        'view' => $served[0]->canView,
        'manage' => $served[0]->canManage,
        'delete' => $served[0]->canDelete,
        'hint' => $served[0]->provisionDefaultRole,
    ];
}

it('does not offer the default-role hint to a manager who cannot assign roles, and does to one who can', function () {
    $catalog = stewardCatalog();
    $authorizer = app(Authorizer::class);

    $manager = Identity::savedActiveAccount('steward.manager@example.org');
    Access::grant($manager, Role::GuardianFull);
    $managerActor = Access::actorFor($manager);

    $admin = Access::admin('steward.admin@example.org');
    $adminActor = Access::actorFor($admin);

    // The isolation is real: the Guardian role's holder manages the borrowed pair and holds no authority to assign a role, and the
    // administrator holds both.
    expect($authorizer->allows($managerActor, Capability::ManageVolunteers))->toBeTrue()
        ->and($authorizer->allows($managerActor, Capability::AssignRoles))->toBeFalse()
        ->and($authorizer->allows($adminActor, Capability::ManageVolunteers))->toBeTrue()
        ->and($authorizer->allows($adminActor, Capability::AssignRoles))->toBeTrue();

    // The manager manages and may delete this type, and is not offered the hint: the type has a default role, and they cannot assign it.
    expect(stewardActions($catalog, $managerActor))->toBe(['view' => true, 'manage' => true, 'delete' => true, 'hint' => false])
        // The positive control: the same type, the same default role, an Actor who can assign roles.
        ->and(stewardActions($catalog, $adminActor))->toBe(['view' => true, 'manage' => true, 'delete' => true, 'hint' => true]);
});

it('does not offer the hint for a type that names no default role, even to an Actor who manages it and can assign roles', function () {
    // The third conjunct: the same type and the same Actor as the positive control above, with no default role to provision.
    $admin = Access::admin('steward.nodefault@example.org');

    expect(stewardActions(stewardCatalog(withDefaultRole: false), Access::actorFor($admin)))
        ->toBe(['view' => true, 'manage' => true, 'delete' => true, 'hint' => false]);
});
