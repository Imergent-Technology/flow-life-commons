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
 *   php worker.php mfa_reset '{"account":"..."}'
 *   php worker.php security_verify '{"account":"...","person":"...","password":"...","code":"..."}'
 *   php worker.php replace_begin '{"account":"...","person":"...","password":"...","code":"..."}'
 *   php worker.php enable '{"account":"..."}'
 *   php worker.php reissue '{"account":"..."}'
 *   php worker.php invite '{"email":"...","name":"..."}'
 *
 * It prints READY just before it starts the use case, then one JSON line, and exits 0 when
 * the operation succeeded, 2 when it was refused or failed. It refuses to run against any
 * database whose name does not end in "_test".
 */

use App\Modules\Access\Application\RevokeRole;
use App\Modules\Access\Application\Role;
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
use App\Modules\Identity\Application\PendingLogin;
use App\Modules\Identity\Application\ReactivationOutcome;
use App\Modules\Identity\Application\ReissueInvitation;
use App\Modules\Identity\Application\RequestPasswordReset;
use App\Modules\Identity\Application\ResetMultiFactor;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Application\SecondFactorNeed;
use App\Modules\Identity\Application\SecondFactorProof;
use App\Modules\Identity\Application\VerifySecurityAccess;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvitationChannel;
use App\Modules\Identity\Domain\InvitationToken;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\Actor;
use App\Shared\Domain\PersonId;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.'.config()->string('database.default').'.database');
if (! is_string($database) || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "worker refuses to run against a database that is not a _test database\n");
    exit(64);
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
    } elseif ($operation === 'enable') {
        if ($app->make(EnableAccount::class)(AccountId::fromString($arg('account'))) !== ReactivationOutcome::Enabled) {
            throw new RuntimeException('the account was not disabled');
        }
    } elseif ($operation === 'reissue') {
        $app->make(ReissueInvitation::class)(AccountId::fromString($arg('account')));
    } elseif ($operation === 'invite') {
        $app->make(InviteAccount::class)(InvitationDetails::from($arg('email'), $arg('name')), null, InvitationChannel::Email);
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
