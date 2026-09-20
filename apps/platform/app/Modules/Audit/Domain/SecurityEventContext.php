<?php

declare(strict_types=1);

namespace App\Modules\Audit\Domain;

/**
 * The small structured context of a security event (ADR 0019).
 *
 * Stored as JSON and NEVER queried into: JSON query functions differ between MariaDB and
 * PostgreSQL and are barred by the portability guardrail, so anything that must be
 * searched belongs in a real column instead.
 *
 * Records never contain credentials, tokens or hashes. This cannot be proven by looking
 * at a value, so it is enforced by shape: a flat map of lowercase snake_case keys to
 * short scalar values, with secret-like keys refused and secret-shaped values refused.
 * That catches the realistic mistakes (a raw token, a session id, a CSRF value, a
 * password hash dropped into `context`); it is a backstop for review, not a substitute.
 */
final readonly class SecurityEventContext
{
    public const int MAX_ENTRIES = 16;

    public const int MAX_VALUE_LENGTH = 128;

    /** Key fragments that name secret material. */
    private const array FORBIDDEN_KEY_FRAGMENTS = [
        'password', 'passwd', 'secret', 'token', 'hash', 'credential', 'session', 'csrf', 'xsrf',
        'cookie', 'authorization', 'bearer', 'apikey', 'api_key', 'private', 'signature',
    ];

    /**
     * A long unbroken run of token characters is what a bearer token, session id, CSRF
     * value or hash looks like. Free text and email addresses contain spaces or '@'.
     */
    private const string SECRET_SHAPE = '/^[A-Za-z0-9_\-+\/=.$]{32,}$/D';

    /** The `$algorithm$...` framing of crypt-style password hashes (bcrypt, argon2). */
    private const string CRYPT_HASH_SHAPE = '/^\$[A-Za-z0-9]+\$/';

    /**
     * @param  array<string, scalar|null>  $values
     */
    private function __construct(public array $values) {}

    /**
     * @param  array<mixed>  $context
     */
    public static function from(array $context): self
    {
        if (count($context) > self::MAX_ENTRIES) {
            throw new InvalidSecurityEvent('A security event context holds at most '.self::MAX_ENTRIES.' entries.');
        }

        $values = [];
        foreach ($context as $key => $value) {
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1) {
                throw new InvalidSecurityEvent('Context keys must be lowercase snake_case names.');
            }
            foreach (self::FORBIDDEN_KEY_FRAGMENTS as $fragment) {
                if (str_contains($key, $fragment)) {
                    throw new InvalidSecurityEvent("Context key \"{$key}\" names secret material and must not be recorded.");
                }
            }
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidSecurityEvent("Context value for \"{$key}\" must be a scalar or null; context is flat.");
            }
            if (is_string($value)) {
                self::assertSafeString($key, $value);
            }

            $values[$key] = $value;
        }

        return new self($values);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    private static function assertSafeString(string $key, string $value): void
    {
        if (mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new InvalidSecurityEvent("Context value for \"{$key}\" is too long to be a summary.");
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidSecurityEvent("Context value for \"{$key}\" contains control characters.");
        }
        if (preg_match(self::SECRET_SHAPE, $value) === 1 || preg_match(self::CRYPT_HASH_SHAPE, $value) === 1) {
            throw new InvalidSecurityEvent("Context value for \"{$key}\" looks like a token, id or hash and must not be recorded.");
        }
    }
}
