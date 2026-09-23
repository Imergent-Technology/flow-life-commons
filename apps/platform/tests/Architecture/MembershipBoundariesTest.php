<?php

declare(strict_types=1);

/*
 * Membership-specific boundaries (ADR 0028, docs/architecture/module-map.md). The generic
 * rules in ModuleBoundariesTest already cover Membership because modules are discovered from
 * disk: Domain depends on no other layer, no other module reaches Membership's Domain,
 * Infrastructure or Http. The rule here is the one the frozen design makes concrete: Access
 * and Identity each depend on Audit, but that is THEIR dependency, not Membership's, and
 * nothing here may create it by calling Audit directly.
 *
 * One subject per expectation (see README.md): an array of subjects passes vacuously.
 */

$membership = 'App\\Modules\\Membership';

arch('Membership: has no direct Audit dependency', function () use ($membership) {
    expect($membership)->not->toUse('App\\Modules\\Audit');
});

arch('Membership: Domain is framework-independent', function () use ($membership) {
    expect("{$membership}\\Domain")->not->toUse('Illuminate');
});

arch('Membership: Domain does not depend on Identity or Access at all', function () use ($membership) {
    // Collaboration with other modules is Application's job; Domain stays a pure model of
    // grants and their derivation.
    expect("{$membership}\\Domain")->not->toUse(['App\\Modules\\Identity', 'App\\Modules\\Access']);
});

arch('Membership: Application does not depend on Infrastructure', function () use ($membership) {
    expect("{$membership}\\Application")->not->toUse("{$membership}\\Infrastructure");
});

arch('Membership: no Eloquent model, so nothing can save() or delete() a grant outside the repository', function () use ($membership) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($membership);
});

arch('Membership: the repository is a port whose database implementation is Infrastructure', function () use ($membership) {
    expect("{$membership}\\Infrastructure\\DatabaseMembershipGrantRepository")->toImplement("{$membership}\\Domain\\MembershipGrantRepository");
});
