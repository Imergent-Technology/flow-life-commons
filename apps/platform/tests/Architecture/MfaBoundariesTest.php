<?php

declare(strict_types=1);

/*
 * The rules multi-factor authentication makes concrete (docs/adr/0023). Scoped to Identity (and to the
 * one Access class that answers its policy port) on purpose. One subject per expectation (see README.md).
 * Source scans carry a positive control, so a rule cannot pass just because it matches nothing.
 */

$identity = 'App\\Modules\\Identity';
$root = dirname(__DIR__, 2);

/**
 * @return array<string, string> path relative to app/ => source, for every PHP file under the given directories
 */
function mfaSources(string ...$dirs): array
{
    $found = [];
    foreach ($dirs as $dir) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            if ($file->getExtension() === 'php') {
                $found[str_replace(dirname($dir, 3).'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }
    }

    return $found;
}

/**
 * @param  array<string, string>  $sources
 * @return list<string>
 */
function mfaMatches(array $sources, string $pattern, string ...$except): array
{
    $hits = [];
    foreach ($sources as $path => $source) {
        if (in_array(basename($path), $except, true)) {
            continue;
        }
        if (preg_match($pattern, $source) === 1) {
            $hits[] = $path;
        }
    }

    return $hits;
}

/** PHP source with its comments removed, so a rule about what code DOES is not tripped by a warning about it. */
function mfaCodeOnly(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }

    return $code;
}

/* --- the TOTP library and the encryption are Infrastructure ------------------------------------------- */

foreach (['OTPHP', 'ParagonIE'] as $library) {
    arch("Identity: {$library} (the TOTP implementation) is used only by its one Infrastructure adapter", function () use ($library) {
        expect($library)->toOnlyBeUsedIn('App\\Modules\\Identity\\Infrastructure\\Mfa\\OtphpTotpAuthenticator');
    });
}

foreach (['Domain', 'Application', 'Http'] as $layer) {
    arch("Identity: {$layer} does not touch the framework's encryption", function () use ($identity, $layer) {
        // Encryption is an Infrastructure concern behind TotpSecretCipher: nothing else names a key or a cipher.
        expect("{$identity}\\{$layer}")->not->toUse([
            'Illuminate\\Contracts\\Encryption',
            'Illuminate\\Encryption',
            'Illuminate\\Support\\Facades\\Crypt',
        ]);
    });

    arch("Identity: {$layer} does not depend on the TOTP library", function () use ($identity, $layer) {
        expect("{$identity}\\{$layer}")->not->toUse(['OTPHP', 'ParagonIE']);
    });
}

arch('Identity: the framework encrypter is used only by the secret cipher', function () {
    expect('Illuminate\\Contracts\\Encryption\\Encrypter')->toOnlyBeUsedIn('App\\Modules\\Identity\\Infrastructure\\Mfa\\LaravelTotpSecretCipher');
});

arch('Identity: the TOTP secret cipher is a port whose implementation is Infrastructure', function () use ($identity) {
    expect("{$identity}\\Infrastructure\\Mfa\\LaravelTotpSecretCipher")->toImplement("{$identity}\\Application\\TotpSecretCipher");
});

arch('Identity: the TOTP authenticator is a port whose implementation is Infrastructure', function () use ($identity) {
    expect("{$identity}\\Infrastructure\\Mfa\\OtphpTotpAuthenticator")->toImplement("{$identity}\\Application\\TotpAuthenticator");
});

it('never asks the TOTP library for a QR-code URL, which would send the secret to another server', function () use ($root) {
    $sources = array_map(mfaCodeOnly(...), mfaSources("{$root}/app/Modules"));

    expect(mfaMatches($sources, '/getQrCodeUri|chart\.googleapis|api\.qrserver|qrcode\.[a-z]+\/|https?:\/\/[^\s\'"]*qr/i'))->toBe([])
        // Positive control: the pattern does see what it is meant to.
        ->and(preg_match('/getQrCodeUri|chart\.googleapis/i', '$totp->getQrCodeUri($uri, $placeholder)'))->toBe(1)
        ->and(preg_match('/https?:\/\/[^\s\'"]*qr/i', "'https://api.example.org/qr?data='"))->toBe(1)
        // ...and comments are not code: a warning about the method is not a call to it.
        ->and(mfaCodeOnly("<?php\n// never call getQrCodeUri()\n\$x = 1;"))->not->toContain('getQrCodeUri');
});

/* --- secrets stay out of the audit trail and out of the wrong classes --------------------------------- */

foreach (['Application\\MfaAudit', 'Application\\AuthenticationAudit', 'Application\\CredentialAudit'] as $audit) {
    foreach (['TotpSecret', 'RecoveryCode', 'SecondFactorProof', 'TotpSetup'] as $secret) {
        arch("Identity: {$audit} cannot see a {$secret}", function () use ($identity, $audit, $secret) {
            // The audit classes are handed reason classes, counts and method names. Not being able to name a
            // secret is the strongest way to guarantee they cannot record one.
            expect("{$identity}\\{$audit}")->not->toUse("{$identity}\\".(str_starts_with($secret, 'Total') || $secret === 'TotpSecret' || $secret === 'RecoveryCode' ? 'Domain\\' : 'Application\\').$secret);
        });
    }
}

arch('Identity: a TotpSecret is revealed only where it is shown once or checked', function () use ($identity) {
    expect("{$identity}\\Domain\\TotpSecret")->toOnlyBeUsedIn([
        "{$identity}\\Application\\TotpAuthenticator",
        "{$identity}\\Application\\TotpSecretCipher",
        "{$identity}\\Application\\TotpSetup",
        "{$identity}\\Application\\SecondFactorVerifier",
        "{$identity}\\Application\\EnrollTotpFixture",
        "{$identity}\\Http\\MfaEnrollmentController",
        "{$identity}\\Http\\AuthenticatorController",
        "{$identity}\\Infrastructure\\Mfa\\OtphpTotpAuthenticator",
        "{$identity}\\Infrastructure\\Mfa\\LaravelTotpSecretCipher",
    ]);
});

arch('Identity: a TotpSecret and a RecoveryCode are sealed, immutable values', function () use ($identity) {
    expect("{$identity}\\Domain\\TotpSecret")->toBeFinal()->toBeReadonly()
        ->and("{$identity}\\Domain\\RecoveryCode")->toBeFinal()->toBeReadonly();
});

arch('Identity: recovery-code storage is a Domain port, implemented in Infrastructure only', function () use ($identity) {
    expect("{$identity}\\Infrastructure\\Persistence\\DatabaseRecoveryCodeRepository")->toImplement("{$identity}\\Domain\\RecoveryCodeRepository");
});

arch('Identity: the Actor and the session hold no factor material', function () {
    expect('App\\Shared\\Domain')->not->toUse([
        'App\\Modules\\Identity\\Domain\\TotpSecret',
        'App\\Modules\\Identity\\Domain\\RecoveryCode',
        'App\\Modules\\Identity\\Application\\SecondFactorProof',
    ]);
});

/* --- the requirement is tied to the surface, never to a role -------------------------------------------- */

it('never names a role in Identity: the multi-factor requirement is not keyed to who someone is', function () use ($root) {
    $identity = mfaSources("{$root}/app/Modules/Identity");
    $pattern = '/platform_administrator|[\'"]guardian[\'"]|\bRole::|Access\\\\Application\\\\Role\b|\bis(Guardian|Admin|Administrator)\b|hasRole|->roles?\b/';

    expect(mfaMatches($identity, $pattern))->toBe([])
        ->and(preg_match($pattern, 'if ($account->isAdministrator()) { require(); }'))->toBe(1)
        ->and(preg_match($pattern, "\$role === 'guardian'"))->toBe(1);
});

it('never adds a capability or a role to represent MFA: it is authentication strength, not authorization', function () use ($root) {
    $capabilities = (string) file_get_contents("{$root}/app/Modules/Access/Application/Capability.php");
    $roles = (string) file_get_contents("{$root}/app/Modules/Access/Application/Role.php");

    expect(preg_match('/case\s+\w*(Mfa|MultiFactor|SecondFactor|Totp)\w*\s*=/i', $capabilities.$roles))->toBe(0);
});

arch('Identity: the multi-factor policy port is consulted from one place, and answered by Access only', function () use ($identity) {
    expect("{$identity}\\Application\\MultiFactorPolicy")->toOnlyBeUsedIn([
        "{$identity}\\Application\\SecondFactorRequirement",
        "{$identity}\\Infrastructure\\IdentityServiceProvider",
        "{$identity}\\Infrastructure\\Mfa\\AlwaysRequireMultiFactor",
        'App\\Modules\\Access\\Application\\ConsoleMultiFactorPolicy',
        'App\\Modules\\Access\\Infrastructure\\AccessServiceProvider',
    ]);
});

arch('Access: its multi-factor policy asks only for a capability, through the Authorizer', function () {
    expect('App\\Modules\\Access\\Application\\ConsoleMultiFactorPolicy')->not->toUse(['App\\Modules\\Access\\Application\\Role', 'App\\Modules\\Access\\Domain']);
});

arch('Identity: the Identity module still does not depend on Access, MFA included', function () use ($identity) {
    expect($identity)->not->toUse('App\\Modules\\Access');
});

/* --- the session's recent-verification state is written in one place ---------------------------------- */

it('writes the session\'s security-verification and pending-sign-in state only in ConsoleSession', function () use ($root) {
    $sources = mfaSources("{$root}/app");
    $pattern = '/second_factor_verified_at|security_verified_at|pending_sign_in|SECURITY_VERIFIED_AT|SECOND_FACTOR_VERIFIED_AT|self::PENDING|ConsoleSession::PENDING/';

    expect(mfaMatches($sources, $pattern, 'ConsoleSession.php'))->toBe([])
        ->and(preg_match($pattern, "\$request->session()->put('security_verified_at', time());"))->toBe(1);
});

it('has no route that answers a credential or factor without the session surface: every MFA route is stateful', function () use ($root) {
    $routes = (string) file_get_contents("{$root}/app/Modules/Identity/Http/routes.php");
    $stateful = substr($routes, (int) strpos($routes, "middleware('stateful')"), (int) strpos($routes, '/*', (int) strpos($routes, "middleware('stateful')") + 10) - (int) strpos($routes, "middleware('stateful')"));

    foreach (['mfa/challenge', 'mfa/enrollment', 'mfa/enrollment/confirm', 'mfa/recovery-codes', 'mfa/authenticator', 'mfa/authenticator/confirm', 'security/verify'] as $path) {
        expect($routes)->toContain("'{$path}'");
    }
    // The stateless credential routes (outside the `stateful` group) name no MFA endpoint.
    $outside = substr($routes, (int) strrpos($routes, '});'));
    expect(str_contains($outside, 'mfa/') || str_contains($outside, 'security/'))->toBeFalse();
});
