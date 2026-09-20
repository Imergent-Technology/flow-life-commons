<?php

declare(strict_types=1);

use App\Modules\Audit\Domain\InvalidSecurityEvent;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventContext;
use App\Modules\Audit\Domain\SecurityEventId;
use Tests\Support\Identity;

function eventOf(string $type = 'authentication.failed', string $outcome = 'failure', ?string $ip = null, ?string $ua = null): SecurityEvent
{
    return new SecurityEvent(
        SecurityEventId::generate(), Identity::now(), $type, $outcome, null, null, null, $ip, $ua, SecurityEventContext::from([]),
    );
}

it('has no actor or subject unless one exists', function () {
    $event = eventOf();

    expect($event->actorAccountId)->toBeNull()
        ->and($event->subjectPersonId)->toBeNull()
        ->and($event->subjectAccountId)->toBeNull();
});

it('accepts dotted lowercase event types and rejects anything else', function (string $type, bool $valid) {
    $build = fn () => eventOf($type);

    $valid ? expect($build())->toBeInstanceOf(SecurityEvent::class) : expect($build)->toThrow(InvalidSecurityEvent::class);
})->with([
    ['authentication.failed', true],
    ['session.absolute_expired', true],
    ['password.reset_requested', true],
    ['logout', false],
    ['Authentication.Failed', false],
    ['authentication.', false],
    ['authentication failed', false],
    ['a.'.str_repeat('b', 64), false],
]);

it('accepts IPv4 and IPv6 addresses and rejects other text', function () {
    expect(eventOf(ip: '203.0.113.7')->ip)->toBe('203.0.113.7')
        ->and(eventOf(ip: '2001:db8::1')->ip)->toBe('2001:db8::1')
        ->and(fn () => eventOf(ip: 'not-an-ip'))->toThrow(InvalidSecurityEvent::class);
});

it('accepts ordinary context', function () {
    $context = SecurityEventContext::from([
        'reason' => 'wrong_password',
        'attempted_identifier' => 'a.very.long.address.that.is.still.an.email@example.organisation',
        'attempts' => 3,
        'firm' => true,
        'note' => null,
        'message' => 'The account is not active and cannot sign in.',
    ]);

    expect($context->values)->toHaveCount(6);
});

it('refuses context keys that name secret material', function (string $key) {
    SecurityEventContext::from([$key => 'x']);
})->with([
    'password', 'password_hash', 'new_password', 'token', 'invitation_token', 'reset_token', 'secret', 'session_id',
    'csrf_token', 'xsrf', 'cookie', 'authorization', 'bearer_value', 'api_key', 'apikey', 'client_secret', 'private_key', 'signature',
])->throws(InvalidSecurityEvent::class);

it('refuses values shaped like a token, session id, CSRF value or hash', function (string $value) {
    SecurityEventContext::from(['detail' => $value]);
})->with([
    'invitation token (43 base64url)' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
    'session id (40 alphanumeric)' => 'kJ3hd83Kd92LsmQp0xZ1vB7nC4yT6uR8eW5qA2gH',
    'sha-256 hex hash' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'bcrypt hash' => '$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234',
    'argon2id hash' => '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$RdescudvJCsgt3ub+b+dWRWJTmaaJObG',
])->throws(InvalidSecurityEvent::class);

it('refuses context that is not small, flat and printable', function (array $context) {
    SecurityEventContext::from($context);
})->with([
    'nested array' => [['reason' => ['a' => 'b']]],
    'object' => [['reason' => new stdClass]],
    'numeric key' => [['a']],
    'upper-case key' => [['Reason' => 'x']],
    'key with dash' => [['re-ason' => 'x']],
    'too many entries' => [array_combine(array_map(fn (int $i): string => "k{$i}", range(1, 17)), range(1, 17))],
    'value too long' => [['reason' => str_repeat('word ', 30)]],
    'control character' => [['reason' => "line\nbreak"]],
])->throws(InvalidSecurityEvent::class);
