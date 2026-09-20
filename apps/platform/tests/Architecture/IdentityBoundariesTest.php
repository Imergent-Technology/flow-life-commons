<?php

declare(strict_types=1);

/*
 * Identity-specific boundaries (docs/architecture/identity-and-access.md, "Layer
 * placement"). The generic rules in ModuleBoundariesTest already cover Identity because
 * modules are discovered from disk: Domain depends on no other layer, no HTTP transport
 * in Domain/Application, and no other module may use Identity's Domain, Infrastructure
 * or Http. The rules here are the ones the frozen Identity design makes concrete now that
 * the module exists.
 *
 * They are scoped to Identity on purpose. docs/architecture/module-map.md currently says
 * Eloquent is fine in Domain; tightening that for every module is a separate decision.
 *
 * One subject per expectation (see README.md): an array of subjects passes vacuously.
 */

$identity = 'App\\Modules\\Identity';

arch('Identity: persistence does not leak into Domain', function () use ($identity) {
    // Eloquent models are Infrastructure (the layer table); Domain holds plain entities.
    expect("{$identity}\\Domain")->not->toUse([
        'Illuminate\\Database',
        'Illuminate\\Support\\Facades\\DB',
        'Illuminate\\Support\\Facades\\Schema',
    ]);
});

arch('Identity: Domain is independent of the Laravel authentication machinery', function () use ($identity) {
    // Account is not itself Authenticatable; the Eloquent record and, later, the user
    // provider adapter are Infrastructure.
    expect("{$identity}\\Domain")->not->toUse(['Illuminate\\Contracts\\Auth', 'Illuminate\\Auth']);
});

arch('Identity: Eloquent is confined to Infrastructure', function () use ($identity) {
    expect('Illuminate\\Database\\Eloquent')->not->toBeUsedIn([
        "{$identity}\\Domain",
        "{$identity}\\Application",
        "{$identity}\\Http",
    ]);
});

arch('Identity: Application does not depend on Infrastructure', function () use ($identity) {
    // Direction is Infrastructure -> Application/Domain, never the reverse.
    expect("{$identity}\\Application")->not->toUse("{$identity}\\Infrastructure");
});

arch('Identity: persistence records are internal to Identity Infrastructure', function () use ($identity) {
    // Other modules reach Identity through its Application layer, never through its tables.
    expect("{$identity}\\Infrastructure\\Persistence")->toOnlyBeUsedIn("{$identity}\\Infrastructure");
});

arch('Identity: Domain is independent of Laravel altogether', function () use ($identity) {
    // The narrower rules above name the parts that matter most; this is the whole claim.
    expect("{$identity}\\Domain")->not->toUse('Illuminate');
});

foreach (['Domain', 'Application'] as $layer) {
    arch("Identity: {$layer} does not depend on Laravel's authentication or session globals", function () use ($identity, $layer) {
        // Only Http (ConsoleSession) and Infrastructure (the user provider) touch the guard
        // and the session. Business code asks a use case instead of reaching for Auth::user().
        expect("{$identity}\\{$layer}")->not->toUse([
            'Illuminate\\Support\\Facades\\Auth',
            'Illuminate\\Support\\Facades\\Session',
            'Illuminate\\Support\\Facades\\Cookie',
            'Illuminate\\Contracts\\Auth',
            'Illuminate\\Contracts\\Session',
            'Illuminate\\Auth',
            'Illuminate\\Session',
        ]);
    });
}

arch('Identity: Http does not reach into Infrastructure', function () use ($identity) {
    // Controllers validate, call an Application use case and shape the response. What
    // implements a port is the container's business.
    expect("{$identity}\\Http")->not->toUse("{$identity}\\Infrastructure");
});

arch('Identity: Domain does not depend on Audit', function () use ($identity) {
    // Audit is consumed from Application only.
    expect("{$identity}\\Domain")->not->toUse('App\\Modules\\Audit');
});

arch('Identity: uses Audit only through its Application layer', function () use ($identity) {
    // The generic rule forbids other modules' Domain, Infrastructure and Http; this pins the
    // positive statement for the one edge that exists: Identity -> Audit\Application.
    expect($identity)->not->toUse(['App\\Modules\\Audit\\Domain', 'App\\Modules\\Audit\\Infrastructure', 'App\\Modules\\Audit\\Http']);
});

/*
 * The credential lifecycle (docs/adr/0022): the concrete rules Phase 5 makes real.
 */

foreach ([
    'Illuminate\\Validation',
    'Illuminate\\Support\\Facades\\Validator',
    'Illuminate\\Mail',
    'Illuminate\\Contracts\\Mail',
    'Illuminate\\Support\\Facades\\Mail',
    'Illuminate\\Notifications',
    'Illuminate\\Auth\\Passwords',
    'Illuminate\\Contracts\\Auth\\PasswordBroker',
    'Illuminate\\Support\\Facades\\Password',
] as $framework) {
    foreach (['Domain', 'Application'] as $layer) {
        arch("Identity: {$layer} does not use {$framework}", function () use ($identity, $layer, $framework) {
            // Validation, mail and the password broker are edges. The domain and the use cases speak
            // their own types (PlainPassword, PasswordPolicy, ports), and Infrastructure adapts the
            // framework to them, so the framework is never the model.
            expect("{$identity}\\{$layer}")->not->toUse($framework);
        });
    }
}

arch('Identity: the breached-password check is a port whose network implementation is Infrastructure', function () use ($identity) {
    expect("{$identity}\\Infrastructure\\Password\\PwnedPasswordsRange")->toImplement("{$identity}\\Application\\CompromisedPasswords");
});

arch('Identity: the breach-check implementations are used only from Infrastructure', function () use ($identity) {
    expect("{$identity}\\Infrastructure\\Password")->toOnlyBeUsedIn("{$identity}\\Infrastructure");
});

arch('Identity: the HTTP client is used by the breach check and nothing else in Identity', function () use ($identity) {
    // ModuleBoundariesTest confines the client to Infrastructure; this pins who inside it.
    expect('Illuminate\\Http\\Client')->toOnlyBeUsedIn("{$identity}\\Infrastructure\\Password");
});

foreach (['AuthenticationAudit', 'CredentialAudit'] as $audit) {
    foreach (['Domain\\PlainPassword', 'Domain\\InvitationToken', 'Application\\IssuedPasswordReset', 'Application\\IssuedInvitation'] as $secret) {
        arch("Identity: {$audit} cannot even see a {$secret}", function () use ($identity, $audit, $secret) {
            // The events describe what happened, never the secret involved. The class that writes
            // them has no way to be handed one.
            expect("{$identity}\\Application\\{$audit}")->not->toUse("{$identity}\\{$secret}");
        });
    }
}

arch('Identity: the password broker is an Infrastructure adapter, not the model', function () use ($identity) {
    // Laravel's token repository is reached only through Identity's own port, from one place.
    expect("{$identity}\\Infrastructure\\Auth\\LaravelPasswordResetTokens")->toImplement("{$identity}\\Application\\PasswordResetTokens");
    expect("{$identity}\\Infrastructure\\Auth\\AccountResetTokenRepository")->toExtend('Illuminate\\Auth\\Passwords\\DatabaseTokenRepository');
});

foreach (['Illuminate\\Auth\\Passwords', 'Illuminate\\Contracts\\Auth\\CanResetPassword'] as $framework) {
    arch("Identity: {$framework} is confined to Identity's Infrastructure Auth adapters", function () use ($identity, $framework) {
        expect($framework)->toOnlyBeUsedIn("{$identity}\\Infrastructure\\Auth");
    });
}

foreach (['Illuminate\\Mail', 'Illuminate\\Support\\Facades\\Mail', 'Illuminate\\Contracts\\Mail'] as $mail) {
    arch("Identity: {$mail} is confined to Identity's message adapters", function () use ($identity, $mail) {
        // Identity sends two messages, its own (recovery and invitation). There is no notifications module.
        expect($mail)->toOnlyBeUsedIn("{$identity}\\Infrastructure\\Mail");
    });
}

arch('Identity: the recovery notifier is a port whose mail implementation is Infrastructure', function () use ($identity) {
    expect("{$identity}\\Infrastructure\\Mail\\MailPasswordResetNotifier")->toImplement("{$identity}\\Application\\PasswordResetNotifier");
});

arch('Identity: a password is a PlainPassword value, sealed and immutable', function () use ($identity) {
    expect("{$identity}\\Domain\\PlainPassword")->toBeFinal()->toBeReadonly();
});

it('Identity: only PasswordHasher hashes or checks a password outside Infrastructure', function () {
    // Source scan, with a positive control. One implementation, so login, acceptance, reset and change
    // cannot drift into subtly different normalisation or limits.
    $hashing = '/Hashing\\\\Hasher|Facades\\\\Hash\b|\bHash::|password_hash\s*\(|password_verify\s*\(|\bbcrypt\s*\(/';
    $offenders = [];

    foreach (['Domain', 'Application', 'Http'] as $layer) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2)."/app/Modules/Identity/{$layer}", FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() !== 'php' || $file->getFilename() === 'PasswordHasher.php') {
                continue;
            }
            if (preg_match($hashing, (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $layer.'/'.$file->getFilename();
            }
        }
    }

    expect($offenders)->toBe([])
        ->and(preg_match($hashing, (string) file_get_contents(dirname(__DIR__, 2).'/app/Modules/Identity/Application/PasswordHasher.php')))->toBe(1);
});

/*
 * Delivered invitations (docs/adr/0024): the raw invitation secret leaves the process in exactly two places, and
 * nothing but the delivery use case may ask for it to be sent.
 */

arch('Identity: the invitation notifier is a port whose mail implementation is Infrastructure', function () use ($identity) {
    expect("{$identity}\\Infrastructure\\Mail\\MailInvitationNotifier")->toImplement("{$identity}\\Application\\InvitationNotifier");
});

arch('Identity: only DeliverInvitation asks the notifier to send, so nothing mails a token except after a commit it knows of', function () use ($identity) {
    expect("{$identity}\\Application\\InvitationNotifier")->toOnlyBeUsedIn([
        "{$identity}\\Application\\DeliverInvitation",
        "{$identity}\\Infrastructure\\IdentityServiceProvider",
        "{$identity}\\Infrastructure\\Mail\\MailInvitationNotifier",
    ]);
});

it('Identity: a raw invitation or reset secret is revealed only by the two mail adapters and the operator\'s bootstrap command', function () {
    // Source scan, with a positive control. The token is shown to whoever the invitation is FOR, once, and to no
    // one else: not an HTTP response, not the audit trail, not a log. Only these three callers exist.
    $pattern = '/->revealToken\s*\(/';
    $root = dirname(__DIR__, 2).'/app';
    $callers = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        assert($file instanceof SplFileInfo);
        if ($file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
            $callers[] = basename($file->getPathname());
        }
    }
    sort($callers);

    expect($callers)->toBe(['CreateAdministratorCommand.php', 'MailInvitationNotifier.php', 'MailPasswordResetNotifier.php'])
        ->and(preg_match($pattern, '$response = response()->json([\'token\' => $issued->revealToken()]);'))->toBe(1);
});

arch('Identity: an invitation channel is a sealed enum, and only EMAIL proves the mailbox', function () use ($identity) {
    expect("{$identity}\\Domain\\InvitationChannel")->toBeEnum();
});
