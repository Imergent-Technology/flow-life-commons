<?php

declare(strict_types=1);

use Tests\Support\SourceScan;

/*
 * The Relationships module's boundaries (ADR 0038). Modules are discovered from disk, so ModuleBoundariesTest and the
 * frozen graph already cover it. These are the Relationships-specific statements.
 *
 * WP1 may use Access, Identity and Audit through their Application layers. It does not take the CRM edge: that seam is WP3.
 * QualifyingRelationships has no caller outside the module until Resources' audience eligibility (WP4).
 */

$relationships = 'App\\Modules\\Relationships';

arch('Relationships uses Identity only through its Application layer', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Identity\\Domain', 'App\\Modules\\Identity\\Infrastructure', 'App\\Modules\\Identity\\Http']);
});

arch('Relationships uses Access only through its Application layer', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Access\\Domain', 'App\\Modules\\Access\\Infrastructure', 'App\\Modules\\Access\\Http']);
});

arch('Relationships uses Audit only through its Application layer, and only DeleteRelationship records an event', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Audit\\Domain', 'App\\Modules\\Audit\\Infrastructure', 'App\\Modules\\Audit\\Http']);
    expect('App\\Modules\\Audit\\Application\\RecordSecurityEvent')->toOnlyBeUsedIn([
        'App\\Modules\\Identity', 'App\\Modules\\Access', 'App\\Modules\\Audit',
        'App\\Modules\\Resources\\Application\\DeletePack', 'App\\Modules\\Resources\\Application\\DeleteCard',
        'App\\Modules\\Relationships\\Application\\DeleteRelationship',
    ]);
});

arch('Relationships does not depend on CRM, Membership, Resources or WordPress', function () use ($relationships) {
    expect($relationships)->not->toUse(['App\\Modules\\Crm', 'App\\Modules\\Membership', 'App\\Modules\\Resources', 'WP_', 'wp_']);
});

arch('Relationships Domain is plain PHP: no framework and no other layer', function () use ($relationships) {
    expect("{$relationships}\\Domain")->not->toUse(['Illuminate', "{$relationships}\\Application", "{$relationships}\\Infrastructure", "{$relationships}\\Http"]);
});

arch('Relationships uses no Eloquent model', function () use ($relationships) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn($relationships);
});

arch('Relationships Http does not reach Infrastructure or the database', function () use ($relationships) {
    expect("{$relationships}\\Http")->not->toUse(["{$relationships}\\Infrastructure", 'Illuminate\\Support\\Facades\\DB', 'Illuminate\\Database']);
});

arch('Relationships does not name a Role', function () use ($relationships) {
    expect($relationships)->not->toUse('App\\Modules\\Access\\Application\\Role');
});

arch('only DescribeRelationshipTypes names a Capability case, and only AssignRoles', function () {
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Relationships/Application', 'app/Modules/Relationships/Http']) as $path) {
        $code = SourceScan::code(SourceScan::read($path));
        if (preg_match('/Capability::(?!AssignRoles\b)\w+/', $code) === 1 || (str_contains($code, 'Capability::') && ! str_ends_with($path, 'DescribeRelationshipTypes.php'))) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([])
        ->and(SourceScan::code(SourceScan::read(SourceScan::root().'/app/Modules/Relationships/Application/DescribeRelationshipTypes.php')))->toContain('Capability::AssignRoles');
});

arch('view use cases ask for the definition view capability and manage use cases ask for manage', function () {
    $root = SourceScan::root().'/app/Modules/Relationships/Application';
    expect(SourceScan::code(SourceScan::read($root.'/ReadRelationship.php')))->toContain('viewCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ListRelationshipDirectory.php')))->toContain('viewCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ReadRelationshipManagement.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/EstablishRelationship.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ChangeRelationshipStatus.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/UpdateRelationshipFields.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/DeleteRelationship.php')))->toContain('manageCapability')
        ->and(SourceScan::code(SourceScan::read($root.'/ListRelationshipCandidates.php')))->toContain('manageCapability');
});

arch('QualifyingRelationships is used only inside Relationships until audience eligibility exists', function () {
    expect('App\\Modules\\Relationships\\Application\\QualifyingRelationships')->toOnlyBeUsedIn('App\\Modules\\Relationships');
});

arch('nothing outside Relationships uses Relationships', function () use ($relationships) {
    expect($relationships)->toOnlyBeUsedIn([$relationships, 'Database\\Seeders']);
});

it('names no other module\'s table', function () {
    $foreign = '/[\'"](?:people|accounts|role_assignments|contact_|discussions|discussion_messages|resource_|membership_grants|security_events)[\'"]/';
    $offenders = [];
    foreach (SourceScan::phpFiles(['app/Modules/Relationships']) as $path) {
        if (preg_match($foreign, SourceScan::code(SourceScan::read($path))) === 1) {
            $offenders[] = SourceScan::relative($path);
        }
    }

    expect($offenders)->toBe([]);
});
