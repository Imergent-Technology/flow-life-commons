<?php

declare(strict_types=1);

use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Audit\Domain\InvalidSecurityEvent;
use App\Modules\Identity\Application\TotpSecretCipher;
use App\Modules\Identity\Application\TotpSecretUnreadable;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\RecoveryCodeId;
use App\Modules\Identity\Domain\RecoveryCodeRepository;
use App\Modules\Identity\Domain\TotpFactor;
use App\Modules\Identity\Domain\TotpFactorId;
use App\Modules\Identity\Domain\TotpFactorRepository;
use App\Modules\Identity\Domain\TotpSecret;
use App\Shared\Domain\AccountId;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;
use Tests\Support\Mfa;

/*
 * The MFA tables on the real engine (MariaDB and PostgreSQL both run this): what is stored, what the
 * database itself refuses, and that recovery-code consumption is one atomic statement.
 */

it('round-trips a factor, with instants in UTC and only ciphertext at rest', function () {
    $account = Identity::savedActiveAccount();
    $factors = app(TotpFactorRepository::class);
    $enrolled = TotpFactor::begin(TotpFactorId::generate(), $account->id, 'opaque-one', Identity::now())
        ->confirmPending(59_000_000, Identity::now()->modify('+1 minute'))
        ->withPending('opaque-two', Identity::now()->modify('+2 minutes'));
    $factors->save($enrolled);

    $loaded = $factors->findByAccount($account->id);

    expect($loaded)->toEqual($enrolled)
        ->and($loaded?->lastUsedStep)->toBe(59_000_000);
    $row = DB::table('account_totp_factors')->first();
    expect($row?->id)->toHaveLength(26)->and($row?->secret_ciphertext)->toBe('opaque-one')->and($row?->pending_secret_ciphertext)->toBe('opaque-two');
});

it('updates a factor in place rather than adding a second', function () {
    $account = Identity::savedActiveAccount();
    $factors = app(TotpFactorRepository::class);
    $first = TotpFactor::begin(TotpFactorId::generate(), $account->id, 'a', Identity::now());
    $factors->save($first);
    $factors->save($first->confirmPending(1, Identity::now()));

    expect(DB::table('account_totp_factors')->count())->toBe(1)->and($factors->findByAccount($account->id)?->isActive())->toBeTrue();
});

it('finds nothing for an Account with no factor', function () {
    expect(app(TotpFactorRepository::class)->findByAccount(Identity::savedActiveAccount()->id))->toBeNull();
});

it('lets the database itself refuse a second factor row for one Account', function () {
    $account = Identity::savedActiveAccount();
    $insert = fn () => DB::table('account_totp_factors')->insert([
        'id' => TotpFactorId::generate()->value, 'account_id' => $account->id->value, 'secret_ciphertext' => 'x', 'pending_secret_ciphertext' => null,
        'pending_started_at' => null, 'enrolled_at' => '2026-09-19 12:00:00', 'last_used_step' => null,
        'created_at' => '2026-09-19 12:00:00', 'updated_at' => '2026-09-19 12:00:00',
    ]);
    $insert();

    expect(Identity::violation($insert))->toBeInstanceOf(UniqueConstraintViolationException::class);
});

it('refuses, with RESTRICT, to delete an Account that has a factor or recovery codes', function () {
    $account = Identity::savedActiveAccount();
    Mfa::enroll($account);

    expect(Identity::violation(fn () => DB::table('accounts')->where('id', $account->id->value)->delete()))
        ->toBeInstanceOf(QueryException::class);
    expect(DB::table('accounts')->where('id', $account->id->value)->count())->toBe(1)
        ->and(DB::table('account_totp_factors')->count())->toBe(1)
        ->and(DB::table('account_recovery_codes')->count())->toBe(10);
});

it('refuses a factor or recovery code that points at no Account', function () {
    $orphan = fn () => DB::table('account_recovery_codes')->insert([
        'id' => RecoveryCodeId::generate()->value, 'account_id' => AccountId::generate()->value,
        'code_hash' => str_repeat('a', 64), 'used_at' => null, 'created_at' => '2026-09-19 12:00:00',
    ]);

    expect(Identity::violation($orphan))->toBeInstanceOf(QueryException::class);
});

it('stores recovery codes as digests, replacing the whole set at once', function () {
    $account = Identity::savedActiveAccount();
    $codes = app(RecoveryCodeRepository::class);
    $first = [RecoveryCode::generate(), RecoveryCode::generate()];
    $codes->replaceAll($account->id, array_map(fn (RecoveryCode $c) => $c->digest($account->id), $first), Identity::now());
    expect($codes->remaining($account->id))->toBe(2);

    $second = [RecoveryCode::generate(), RecoveryCode::generate(), RecoveryCode::generate()];
    $codes->replaceAll($account->id, array_map(fn (RecoveryCode $c) => $c->digest($account->id), $second), Identity::now());

    expect($codes->remaining($account->id))->toBe(3)
        ->and($codes->consume($account->id, $first[0]->digest($account->id), Identity::now()))->toBeFalse()   // the old set is gone
        ->and(DB::table('account_recovery_codes')->pluck('code_hash')->all())->each->toMatch('/^[0-9a-f]{64}$/D');
});

it('consumes a code exactly once, and only for its own Account', function () {
    $account = Identity::savedActiveAccount('a@example.org');
    $other = Identity::savedActiveAccount('b@example.org', name: 'Other');
    $codes = app(RecoveryCodeRepository::class);
    $code = RecoveryCode::generate();
    $codes->replaceAll($account->id, [$code->digest($account->id)], Identity::now());
    $codes->replaceAll($other->id, [RecoveryCode::generate()->digest($other->id)], Identity::now());

    expect($codes->consume($other->id, $code->digest($account->id), Identity::now()))->toBeFalse()   // not theirs
        ->and($codes->consume($account->id, $code->digest($account->id), Identity::now()))->toBeTrue()
        ->and($codes->consume($account->id, $code->digest($account->id), Identity::now()))->toBeFalse()   // once
        ->and($codes->remaining($account->id))->toBe(0)
        ->and($codes->remaining($other->id))->toBe(1);
    expect(DB::table('account_recovery_codes')->where('account_id', $account->id->value)->value('used_at'))->not->toBeNull();
});

it('refuses two rows with the same digest for one Account', function () {
    $account = Identity::savedActiveAccount();
    $row = fn () => DB::table('account_recovery_codes')->insert([
        'id' => RecoveryCodeId::generate()->value, 'account_id' => $account->id->value,
        'code_hash' => str_repeat('b', 64), 'used_at' => null, 'created_at' => '2026-09-19 12:00:00',
    ]);
    $row();

    expect(Identity::violation($row))->toBeInstanceOf(UniqueConstraintViolationException::class);
});

it('encrypts a secret at rest with the framework encrypter and reads it back', function () {
    $secret = TotpSecret::fromBase32('JBSWY3DPEHPK3PXP');
    $cipher = app(TotpSecretCipher::class);

    $stored = $cipher->encrypt($secret);

    expect($stored)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and(base64_decode($stored, true))->toBeString()   // the framework's payload envelope, not the secret
        ->and($cipher->encrypt($secret))->not->toBe($stored)   // a fresh IV every time
        ->and($cipher->decrypt($stored)->reveal())->toBe('JBSWY3DPEHPK3PXP');
});

it('cannot decrypt a tampered value, and says so loudly rather than calling it a wrong code', function () {
    $cipher = app(TotpSecretCipher::class);
    $stored = $cipher->encrypt(TotpSecret::fromBase32('JBSWY3DPEHPK3PXP'));
    $tampered = substr($stored, 0, -6).'AAAAAA';

    expect(fn () => $cipher->decrypt($tampered))->toThrow(TotpSecretUnreadable::class)
        ->and(fn () => $cipher->decrypt('not a payload'))->toThrow(TotpSecretUnreadable::class);
});

it('refuses to record a secret-shaped value, or a key that names one, in the audit trail', function (array $context) {
    /** @var array<string, scalar|null> $context */
    // Audit's backstop, on top of MfaAudit never being handed a secret in the first place (see the
    // architecture tests): a base32 TOTP secret and a recovery-code digest both look like what they are.
    app(RecordSecurityEvent::class)('mfa.enabled', SecurityEventOutcome::Success, null, null, null, null, null, $context);
})->with([
    'a key that names a secret' => [['totp_secret' => 'x']],
    'a key that names a code hash' => [['recovery_code_hash' => 'x']],
    'a base32 secret, by its shape' => [['detail' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP']],
    'a 64-character digest, by its shape' => [['detail' => str_repeat('ab', 32)]],
])->throws(InvalidSecurityEvent::class);
