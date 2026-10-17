<?php

declare(strict_types=1);

use App\Modules\Access\Application\AccessDenied;
use App\Modules\Access\Application\Role;
use App\Modules\Crm\Application\AddContactMethod;
use App\Modules\Crm\Application\CreateTag;
use App\Modules\Crm\Application\DeleteTag;
use App\Modules\Crm\Application\EditInteraction;
use App\Modules\Crm\Application\GetPersonRecord;
use App\Modules\Crm\Application\ListInteractions;
use App\Modules\Crm\Application\ListTags;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\NewInteraction;
use App\Modules\Crm\Application\PagePeopleDirectory;
use App\Modules\Crm\Application\PersonRegistration;
use App\Modules\Crm\Application\ProfileChanges;
use App\Modules\Crm\Application\RecordInteraction;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Application\RemoveContactMethod;
use App\Modules\Crm\Application\RemoveInteraction;
use App\Modules\Crm\Application\RenameTag;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Application\UpdateContactMethod;
use App\Modules\Crm\Application\UpdatePerson;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Crm\Domain\InteractionId;
use App\Modules\Crm\Domain\InteractionKind;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use Tests\Support\Access;
use Tests\Support\Identity;

/*
 * G6 WP2-06. Every CRM use case asks for its capability BEFORE it looks anything up, so an Actor without it learns
 * nothing about which Persons, notes, tags or contact methods exist (a "not found" would tell a stranger an id is
 * free, "forbidden" that it is taken). The Person, note, tag and contact method here do not exist: a use case that
 * looked first would answer UnknownPerson, InteractionNotFound, TagNotFound or ContactMethodNotFound, not AccessDenied.
 * Discussions pins the same rule for itself (DiscussionsTest, "asks every use case for its capability BEFORE...").
 */

/** One call per CRM use case, with ids that exist nowhere. */
function crmUseCaseCall(string $useCase, Actor $actor): mixed
{
    $person = PersonId::generate();
    $note = InteractionId::generate();
    $tag = ContactTagId::generate();
    $method = ContactMethodId::generate();

    return match ($useCase) {
        GetPersonRecord::class => app(GetPersonRecord::class)($actor, $person),
        PagePeopleDirectory::class => app(PagePeopleDirectory::class)($actor, null, $tag, 1, 25),
        ListTags::class => app(ListTags::class)($actor),
        ListInteractions::class => app(ListInteractions::class)($actor, $person, 1, 25),
        RegisterContact::class => app(RegisterContact::class)($actor, 'Nobody', null, null, [], true),
        UpdatePerson::class => app(UpdatePerson::class)($actor, $person, 'Renamed', new ProfileChanges),
        AddContactMethod::class => app(AddContactMethod::class)($actor, $person, new NewContactMethod(ContactMethodKind::Email, 'x@example.org')),
        UpdateContactMethod::class => app(UpdateContactMethod::class)($actor, $person, $method, ['label' => 'x']),
        RemoveContactMethod::class => app(RemoveContactMethod::class)($actor, $person, $method),
        SetPersonTags::class => app(SetPersonTags::class)($actor, $person, [$tag]),
        CreateTag::class => app(CreateTag::class)($actor, 'A tag'),
        RenameTag::class => app(RenameTag::class)($actor, $tag, 'Renamed'),
        DeleteTag::class => app(DeleteTag::class)($actor, $tag),
        RecordInteraction::class => app(RecordInteraction::class)($actor, $person, new NewInteraction(InteractionKind::Note, 'x')),
        EditInteraction::class => app(EditInteraction::class)($actor, $person, $note, ['body' => 'x']),
        RemoveInteraction::class => app(RemoveInteraction::class)($actor, $person, $note),
        default => throw new InvalidArgumentException($useCase),
    };
}

/** @return list<class-string> the use cases crmUseCaseCall() can call */
function crmListedUseCases(): array
{
    return [
        GetPersonRecord::class, PagePeopleDirectory::class, ListTags::class, ListInteractions::class, RegisterContact::class, UpdatePerson::class,
        AddContactMethod::class, UpdateContactMethod::class, RemoveContactMethod::class, SetPersonTags::class, CreateTag::class, RenameTag::class,
        DeleteTag::class, RecordInteraction::class, EditInteraction::class, RemoveInteraction::class,
    ];
}

/**
 * Every public CRM use case: the classes under Application with an `__invoke` taking an Actor. `PersonRegistration` is the one
 * carve-out: the shared implementation behind RegisterContact and the delegated RegisterMinimalPerson, which authorizes nothing
 * by design because its callers do (pinned in CrmBoundariesTest).
 *
 * @return list<string>
 */
function crmUseCaseClasses(): array
{
    $found = [];
    foreach (glob(app_path('Modules/Crm/Application/*.php')) ?: [] as $file) {
        $class = 'App\\Modules\\Crm\\Application\\'.basename($file, '.php');
        if ($class === PersonRegistration::class || ! class_exists($class) || ! method_exists($class, '__invoke')) {
            continue;
        }
        $first = (new ReflectionMethod($class, '__invoke'))->getParameters()[0] ?? null;
        $type = $first?->getType();
        if ($type instanceof ReflectionNamedType && $type->getName() === Actor::class) {
            $found[] = $class;
        }
    }
    sort($found);

    return $found;
}

it('covers every CRM use case that takes an Actor', function () {
    $listed = crmListedUseCases();
    sort($listed);

    // A use case added without a line in crmUseCaseCall() fails here, so the ordering rule cannot silently skip it.
    expect(crmUseCaseClasses())->toBe($listed);
});

it('refuses with AccessDenied, before any lookup, an Actor who holds no CRM capability', function (string $useCase, string $holder) {
    $account = Identity::savedActiveAccount('stranger@example.org');
    if ($holder === 'console-participant') {
        Access::grant($account, Role::ConsoleParticipant); // admitted to the Console, and nothing in CRM
    }
    $before = [
        DB::table('people')->count(), DB::table('contact_tags')->count(), DB::table('contact_interactions')->count(), DB::table('contact_methods')->count(),
    ];

    expect(fn () => crmUseCaseCall($useCase, Access::actorFor($account)))->toThrow(AccessDenied::class);

    expect([DB::table('people')->count(), DB::table('contact_tags')->count(), DB::table('contact_interactions')->count(), DB::table('contact_methods')->count()])
        ->toBe($before);
})->with(array_map(fn (string $useCase): string => $useCase, [
    GetPersonRecord::class, PagePeopleDirectory::class, ListTags::class, ListInteractions::class, RegisterContact::class, UpdatePerson::class,
    AddContactMethod::class, UpdateContactMethod::class, RemoveContactMethod::class, SetPersonTags::class, CreateTag::class, RenameTag::class,
    DeleteTag::class, RecordInteraction::class, EditInteraction::class, RemoveInteraction::class,
]))->with(['no role', 'console-participant']);
