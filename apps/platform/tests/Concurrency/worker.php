<?php

declare(strict_types=1);

/*
 * A second PHP process for the concurrency tests: boots the application, runs ONE use case,
 * and reports what happened. It is launched by AdministratorRemovalRaceTest while the test
 * process is holding an open transaction, and is expected to BLOCK on the administrator lock
 * until that transaction commits.
 *
 *   php worker.php revoke  '{"actor_account":"...","actor_person":"...","person":"..."}'
 *   php worker.php disable '{"account":"..."}'
 *   php worker.php login   '{"email":"...","password":"..."}'
 *   php worker.php accept  '{"token":"...","password":"..."}'
 *   php worker.php lock_invitation '{"token":"..."}'
 *   php worker.php reset   '{"email":"...","token":"...","password":"..."}'
 *   php worker.php request_reset '{"email":"..."}'
 *   php worker.php change  '{"account":"...","person":"...","current":"...","password":"..."}'
 *   php worker.php mfa_complete '{"account":"...","marker":"...","need":"challenge","code":"..."}'  (or "recovery_code")
 *   php worker.php mfa_confirm_enrollment '{"account":"...","marker":"...","code":"..."}'
 *   php worker.php consume_code '{"account":"...","digest":"..."}'
 *   php worker.php revoke_grant '{"actor_account":"...","actor_person":"...","grant":"..."}'
 *   php worker.php mfa_reset '{"account":"..."}'
 *   php worker.php security_verify '{"account":"...","person":"...","password":"...","code":"..."}'
 *   php worker.php replace_begin '{"account":"...","person":"...","password":"...","code":"..."}'
 *   php worker.php managed_disable '{"actor_account":"...","actor_person":"...","account":"..."}'
 *   php worker.php enable '{"account":"..."}'
 *   php worker.php reissue '{"account":"..."}'
 *   php worker.php invite '{"email":"...","name":"..."}'
 *   php worker.php invite_existing_person '{"person":"...","email":"..."}'
 *   php worker.php discussion_reply|discussion_resolve|discussion_reopen '{"actor_account":"...","actor_person":"...","discussion":"..."[,"body":"..."]}'
 *   php worker.php resources_<operation> '{"actor_account":"...","actor_person":"...",...}'   (see the branches below: publish_pack, unpublish_pack, delete_pack,
 *       delete_card, publish_card, unpublish_card, set_pack_audiences, set_card_audiences, create_card, create_pack, create_category, reorder_cards,
 *       reorder_categories, update_pack, update_card, delete_category, move_pack, replace_file with "name" and base64 "bytes")
 *   php worker.php discussion_edit|discussion_remove '{"actor_account":"...","actor_person":"...","discussion":"...","message":"..."[,"body":"..."]}'
 *
 * It prints READY just before it starts the use case, then one JSON line, and exits 0 when
 * the operation succeeded, 2 when it was refused or failed. It refuses to run against any
 * database whose name does not end in "_test".
 */

use App\Modules\Access\Application\DisableManagedAccount;
use App\Modules\Access\Application\GrantSourcedRole;
use App\Modules\Access\Application\ProvisionableRole;
use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
use App\Modules\Access\Application\RoleGrantSource;
use App\Modules\Access\Application\WithdrawSourcedRoles;
use App\Modules\Crm\Application\AddContactMethod;
use App\Modules\Crm\Application\CreateTag;
use App\Modules\Crm\Application\DeleteTag;
use App\Modules\Crm\Application\NewContactMethod;
use App\Modules\Crm\Application\RegisterContact;
use App\Modules\Crm\Application\RenameTag;
use App\Modules\Crm\Application\SetPersonTags;
use App\Modules\Crm\Application\UpdateContactMethod;
use App\Modules\Crm\Domain\ContactMethodId;
use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactTagId;
use App\Modules\Discussions\Application\EditOwnMessage;
use App\Modules\Discussions\Application\RemoveOwnMessage;
use App\Modules\Discussions\Application\ReopenDiscussion;
use App\Modules\Discussions\Application\ReplyToDiscussion;
use App\Modules\Discussions\Application\ResolveDiscussion;
use App\Modules\Discussions\Domain\DiscussionId;
use App\Modules\Discussions\Domain\DiscussionMessageId;
use App\Modules\Identity\Application\AcceptInvitation;
use App\Modules\Identity\Application\AuthenticateAccount;
use App\Modules\Identity\Application\AuthenticationStatus;
use App\Modules\Identity\Application\BeginAuthenticatorReplacement;
use App\Modules\Identity\Application\ChangePassword;
use App\Modules\Identity\Application\ClientContext;
use App\Modules\Identity\Application\CompleteSecondFactor;
use App\Modules\Identity\Application\ConfirmTotpEnrollment;
use App\Modules\Identity\Application\DisableAccount;
use App\Modules\Identity\Application\EnableAccount;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InviteAccount;
use App\Modules\Identity\Application\InviteAccountForPerson;
use App\Modules\Identity\Application\PendingLogin;
use App\Modules\Identity\Application\ReactivationOutcome;
use App\Modules\Identity\Application\ReissueInvitation;
use App\Modules\Identity\Application\RenamePerson;
use App\Modules\Identity\Application\RequestPasswordReset;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Application\SecondFactorNeed;
use App\Modules\Identity\Application\SecondFactorProof;
use App\Modules\Identity\Application\VerifySecurityAccess;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Membership\Application\RevokeMembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Relationships\Application\ChangeRelationshipStatus;
use App\Modules\Relationships\Application\DeleteRelationship;
use App\Modules\Relationships\Application\EstablishRelationship;
use App\Modules\Relationships\Application\RelationshipCatalog;
use App\Modules\Relationships\Application\UpdateRelationshipFields;
use App\Modules\Relationships\Domain\RelationshipId;
use App\Modules\Resources\Application\CreateCard;
use App\Modules\Resources\Application\CreateCategory;
use App\Modules\Resources\Application\CreatePack;
use App\Modules\Resources\Application\DeleteCard;
use App\Modules\Resources\Application\DeleteCategory;
use App\Modules\Resources\Application\DeletePack;
use App\Modules\Resources\Application\IncomingFile;
use App\Modules\Resources\Application\PublishCard;
use App\Modules\Resources\Application\PublishPack;
use App\Modules\Resources\Application\ReorderCards;
use App\Modules\Resources\Application\ReorderCategories;
use App\Modules\Resources\Application\ReplaceCardFile;
use App\Modules\Resources\Application\SetCardAudiences;
use App\Modules\Resources\Application\SetPackAudiences;
use App\Modules\Resources\Application\UnpublishCard;
use App\Modules\Resources\Application\UnpublishPack;
use App\Modules\Resources\Application\UpdateCard;
use App\Modules\Resources\Application\UpdatePack;
use App\Modules\Resources\Domain\Audience;
use App\Modules\Resources\Domain\AudienceMode;
use App\Modules\Resources\Domain\CardId;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\CategoryId;
use App\Modules\Resources\Domain\PackId;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.'.config()->string('database.default').'.database');
if (! is_string($database) || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "worker refuses to run against a database that is not a _test database\n");
    exit(64);
}

// The test process's Resources file store, so both processes of a race write and remove files in one place (tests/Support/ResourceFiles).
$diskRoot = getenv('RESOURCES_TEST_DISK_ROOT');
if (is_string($diskRoot) && $diskRoot !== '') {
    config(['filesystems.disks.resources.root' => $diskRoot]);
    Storage::forgetDisk('resources');
}

$operation = $argv[1] ?? '';
$decoded = json_decode($argv[2] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
assert(is_array($decoded));
$arg = static function (string $name) use ($decoded): string {
    $value = $decoded[$name] ?? null;

    return is_string($value) ? $value : throw new InvalidArgumentException("missing argument {$name}");
};

echo "READY\n";
flush();

try {
    if ($operation === 'revoke') {
        $app->make(RevokeRole::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            PersonId::fromString($arg('person')),
            Role::PlatformAdministrator,
        );
    } elseif ($operation === 'disable') {
        $app->make(DisableAccount::class)(AccountId::fromString($arg('account')));
    } elseif ($operation === 'login') {
        $result = $app->make(AuthenticateAccount::class)(
            EmailAddress::fromString($arg('email')), $arg('password'), new ClientContext('127.0.0.1', 'worker'),
        );
        if ($result->status !== AuthenticationStatus::Authenticated) {
            throw new RuntimeException('login was not accepted');
        }
    } elseif ($operation === 'accept') {
        $app->make(AcceptInvitation::class)($arg('token'), $arg('password'), new ClientContext('127.0.0.1', 'worker'));
    } elseif ($operation === 'reset') {
        $app->make(ResetPassword::class)(EmailAddress::fromString($arg('email')), $arg('token'), $arg('password'), new ClientContext('127.0.0.1', 'worker'));
    } elseif ($operation === 'change') {
        $app->make(ChangePassword::class)(
            Actor::user(AccountId::fromString($arg('account')), PersonId::fromString($arg('person'))),
            $arg('current'), $arg('password'), 'the-workers-own-session', new ClientContext('127.0.0.1', 'worker'),
        );
    } elseif ($operation === 'request_reset') {
        $app->make(RequestPasswordReset::class)(EmailAddress::fromString($arg('email')), new ClientContext('127.0.0.1', 'worker'));
    } elseif ($operation === 'mfa_complete') {
        $proof = isset($decoded['recovery_code']) ? SecondFactorProof::recoveryCode($arg('recovery_code')) : SecondFactorProof::totp($arg('code'));
        $outcome = $app->make(CompleteSecondFactor::class)(
            new PendingLogin(AccountId::fromString($arg('account')), $arg('marker'), SecondFactorNeed::from($arg('need'))),
            $proof, new ClientContext('127.0.0.1', 'worker'),
        );
        if (! $outcome->succeeded()) {
            throw new RuntimeException('the second factor was refused: '.($outcome->failure->value ?? '?'));
        }
    } elseif ($operation === 'mfa_confirm_enrollment') {
        $outcome = $app->make(ConfirmTotpEnrollment::class)(
            new PendingLogin(AccountId::fromString($arg('account')), $arg('marker'), SecondFactorNeed::Enrollment),
            SecondFactorProof::totp($arg('code')), new ClientContext('127.0.0.1', 'worker'),
        );
        if (! $outcome->succeeded()) {
            throw new RuntimeException('the enrolment was refused: '.($outcome->failure->value ?? '?'));
        }
    } elseif ($operation === 'consume_code') {
        // Just the conditional update, with NO Account lock: it must be atomic on its own.
        $consumed = DB::transaction(fn (): bool => $app->make(RecoveryCodeRepository::class)->consume(
            AccountId::fromString($arg('account')), $arg('digest'), new DateTimeImmutable('now'),
        ));
        if (! $consumed) {
            throw new RuntimeException('the code was already spent');
        }
    } elseif ($operation === 'revoke_grant') {
        $app->make(RevokeMembershipGrant::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            MembershipGrantId::fromString($arg('grant')),
        );
    } elseif ($operation === 'mfa_reset') {
        $app->make(ResetMultiFactor::class)->fromServer(AccountId::fromString($arg('account')));
    } elseif ($operation === 'security_verify') {
        $app->make(VerifySecurityAccess::class)(
            Actor::user(AccountId::fromString($arg('account')), PersonId::fromString($arg('person'))),
            $arg('password'), SecondFactorProof::totp($arg('code')), new ClientContext('127.0.0.1', 'worker'),
        );
    } elseif ($operation === 'replace_begin') {
        $app->make(BeginAuthenticatorReplacement::class)(
            Actor::user(AccountId::fromString($arg('account')), PersonId::fromString($arg('person'))),
            $arg('password'), SecondFactorProof::totp($arg('code')), new ClientContext('127.0.0.1', 'worker'),
        );
    } elseif ($operation === 'managed_disable') {
        $app->make(DisableManagedAccount::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            AccountId::fromString($arg('account')),
        );
    } elseif ($operation === 'enable') {
        if ($app->make(EnableAccount::class)(AccountId::fromString($arg('account'))) !== ReactivationOutcome::Enabled) {
            throw new RuntimeException('the account was not disabled');
        }
    } elseif ($operation === 'crm_add_method') {
        $app->make(AddContactMethod::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            PersonId::fromString($arg('person')),
            new NewContactMethod(ContactMethodKind::from($arg('kind')), $arg('value'), null, $arg('primary') === '1'),
        );
    } elseif ($operation === 'crm_set_primary') {
        $app->make(UpdateContactMethod::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            PersonId::fromString($arg('person')), ContactMethodId::fromString($arg('method')), ['is_primary' => true],
        );
    } elseif ($operation === 'crm_create_tag') {
        $app->make(CreateTag::class)(Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))), $arg('name'));
    } elseif ($operation === 'crm_delete_tag') {
        $app->make(DeleteTag::class)(Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))), ContactTagId::fromString($arg('tag')));
    } elseif ($operation === 'crm_rename_tag') {
        $app->make(RenameTag::class)(Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))), ContactTagId::fromString($arg('tag')), $arg('name'));
    } elseif ($operation === 'crm_set_tags') {
        $app->make(SetPersonTags::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            PersonId::fromString($arg('person')), [ContactTagId::fromString($arg('tag'))],
        );
    } elseif ($operation === 'crm_register') {
        $app->make(RegisterContact::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            $arg('name'), null, null, [new NewContactMethod(ContactMethodKind::Email, $arg('email'))], false,
        );
    } elseif ($operation === 'discussion_reply') {
        $app->make(ReplyToDiscussion::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            DiscussionId::fromString($arg('discussion')), $arg('body'),
        );
    } elseif ($operation === 'discussion_resolve') {
        $app->make(ResolveDiscussion::class)(Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))), DiscussionId::fromString($arg('discussion')));
    } elseif ($operation === 'discussion_reopen') {
        $app->make(ReopenDiscussion::class)(Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))), DiscussionId::fromString($arg('discussion')));
    } elseif ($operation === 'discussion_edit') {
        $app->make(EditOwnMessage::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            DiscussionId::fromString($arg('discussion')), DiscussionMessageId::fromString($arg('message')), $arg('body'),
        );
    } elseif ($operation === 'discussion_remove') {
        $app->make(RemoveOwnMessage::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            DiscussionId::fromString($arg('discussion')), DiscussionMessageId::fromString($arg('message')),
        );
    } elseif (str_starts_with($operation, 'resources_')) {
        $actor = Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person')));
        $audiences = static fn (string $csv): array => array_values(array_map(Audience::from(...), array_filter(explode(',', $csv), static fn (string $a): bool => $a !== '')));
        $doc = static fn (string $text): array => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]]]]];
        match ($operation) {
            'resources_publish_pack' => $app->make(PublishPack::class)($actor, PackId::fromString($arg('pack'))),
            'resources_unpublish_pack' => $app->make(UnpublishPack::class)($actor, PackId::fromString($arg('pack'))),
            'resources_delete_pack' => $app->make(DeletePack::class)($actor, PackId::fromString($arg('pack'))),
            'resources_delete_card' => $app->make(DeleteCard::class)($actor, PackId::fromString($arg('pack')), CardId::fromString($arg('card'))),
            'resources_publish_card' => $app->make(PublishCard::class)($actor, PackId::fromString($arg('pack')), CardId::fromString($arg('card'))),
            'resources_unpublish_card' => $app->make(UnpublishCard::class)($actor, PackId::fromString($arg('pack')), CardId::fromString($arg('card'))),
            'resources_set_pack_audiences' => $app->make(SetPackAudiences::class)($actor, PackId::fromString($arg('pack')), $audiences($arg('audiences'))),
            'resources_set_card_audiences' => $app->make(SetCardAudiences::class)($actor, PackId::fromString($arg('pack')), CardId::fromString($arg('card')), AudienceMode::from($arg('mode')), $audiences($arg('audiences'))),
            'resources_create_card' => $app->make(CreateCard::class)($actor, PackId::fromString($arg('pack')), CardType::Basic, $arg('title'), $doc($arg('text')), null, null),
            'resources_create_pack' => $app->make(CreatePack::class)($actor, $arg('title'), null, false, $arg('category') === '' ? null : CategoryId::fromString($arg('category'))),
            'resources_create_category' => $app->make(CreateCategory::class)($actor, $arg('name')),
            'resources_reorder_cards' => $app->make(ReorderCards::class)($actor, PackId::fromString($arg('pack')), array_map(CardId::fromString(...), array_values(array_filter(explode(',', $arg('ids')))))),
            'resources_reorder_categories' => $app->make(ReorderCategories::class)($actor, array_map(CategoryId::fromString(...), array_values(array_filter(explode(',', $arg('ids')))))),
            'resources_update_pack' => $app->make(UpdatePack::class)($actor, PackId::fromString($arg('pack')), (int) $arg('revision'), ['title' => $arg('title')]),
            'resources_move_pack' => $app->make(UpdatePack::class)($actor, PackId::fromString($arg('pack')), (int) $arg('revision'), ['category_id' => $arg('category') === '' ? null : $arg('category')]),
            'resources_update_card' => $app->make(UpdateCard::class)($actor, PackId::fromString($arg('pack')), CardId::fromString($arg('card')), (int) $arg('revision'), isset($decoded['text']) ? ['content' => $doc($arg('text'))] : ['title' => $arg('title')]),
            'resources_delete_category' => $app->make(DeleteCategory::class)($actor, CategoryId::fromString($arg('category'))),
            'resources_replace_file' => $app->make(ReplaceCardFile::class)($actor, PackId::fromString($arg('pack')), CardId::fromString($arg('card')), (static function () use ($arg): IncomingFile {
                $path = (string) tempnam(sys_get_temp_dir(), 'flc-worker-');
                file_put_contents($path, (string) base64_decode($arg('bytes'), true));

                return new IncomingFile($path, $arg('name'));
            })()),
            default => throw new InvalidArgumentException("unknown operation {$operation}"),
        };
    } elseif ($operation === 'rename_person') {
        $app->make(RenamePerson::class)(PersonId::fromString($arg('person')), $arg('name'));
    } elseif ($operation === 'reissue') {
        $app->make(ReissueInvitation::class)(AccountId::fromString($arg('account')));
    } elseif ($operation === 'invite') {
        $app->make(InviteAccount::class)->byEmail(InvitationDetails::from($arg('email'), $arg('name')));
    } elseif ($operation === 'invite_existing_person') {
        $app->make(InviteAccountForPerson::class)(PersonId::fromString($arg('person')), $arg('email'));
    } elseif ($operation === 'relationship_intake') {
        $type = $app->make(RelationshipCatalog::class)->type($arg('type')) ?? throw new InvalidArgumentException('type');
        $app->make(EstablishRelationship::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            $type, PersonId::fromString($arg('person')), true, $arg('status'), [], false,
        );
    } elseif ($operation === 'relationship_status') {
        $type = $app->make(RelationshipCatalog::class)->type($arg('type')) ?? throw new InvalidArgumentException('type');
        $app->make(ChangeRelationshipStatus::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            $type, PersonId::fromString($arg('person')), RelationshipId::fromString($arg('relationship')),
            (int) $arg('revision'), $arg('status'), false,
        );
    } elseif ($operation === 'relationship_fields') {
        $type = $app->make(RelationshipCatalog::class)->type($arg('type')) ?? throw new InvalidArgumentException('type');
        $app->make(UpdateRelationshipFields::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            $type, PersonId::fromString($arg('person')), RelationshipId::fromString($arg('relationship')),
            (int) $arg('revision'), ['interests' => $arg('value')],
        );
    } elseif ($operation === 'relationship_delete') {
        $type = $app->make(RelationshipCatalog::class)->type($arg('type')) ?? throw new InvalidArgumentException('type');
        $app->make(DeleteRelationship::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            $type, PersonId::fromString($arg('person')), RelationshipId::fromString($arg('relationship')), (int) $arg('revision'),
        );
    } elseif ($operation === 'grant_sourced_role') {
        $app->make(GrantSourcedRole::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            PersonId::fromString($arg('person')),
            ProvisionableRole::from($arg('role')),
            RoleGrantSource::relationship($arg('source')),
        );
    } elseif ($operation === 'withdraw_sourced_roles') {
        $app->make(WithdrawSourcedRoles::class)(
            Actor::user(AccountId::fromString($arg('actor_account')), PersonId::fromString($arg('actor_person'))),
            RoleGrantSource::relationship($arg('source')),
        );
    } elseif ($operation === 'lock_invitation') {
        // Just takes and releases the invitation row lock: it finishes only once it has been granted.
        DB::transaction(fn () => $app->make(AccountInvitationRepository::class)->findByTokenForUpdate(InvitationToken::fromPresented($arg('token'))));
    } else {
        throw new InvalidArgumentException("unknown operation {$operation}");
    }
    echo json_encode(['result' => 'done'])."\n";
    exit(0);
} catch (Throwable $e) {
    echo json_encode(['result' => 'refused', 'class' => $e::class])."\n";
    exit(2);
}
