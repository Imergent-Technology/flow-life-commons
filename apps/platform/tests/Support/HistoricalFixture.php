<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Hash;

/**
 * Synthetic data in the shape production v0.3.0 could hold (G6 WP3b, D9), written as LITERAL ROWS into the 15-table v0.3.0 schema.
 *
 * Nothing here calls the application. The current domain, Access and Identity code reads tables the v0.3.0 schema does not have
 * (the Authorizer reads `sourced_role_grants`), so it cannot run against that schema, and a fixture built by it would change whenever
 * that code does. Every identifier is a fixed ULID, so a failure names the same row on every run and on both engines.
 *
 * The only values made at run time are the ones that would be credentials if they were committed: password hashes, the TOTP secret and
 * its ciphertext, recovery-code and invitation digests. Nothing real is in this file, and no secret.
 *
 * The roles use only keys v0.3.0 could store (`platform_administrator`, `guardian`), plus the variants a database can hold that its
 * catalog never wrote (ADR 0017: the key is a VARCHAR). MariaDB's unique key on (person_id, role_key) folds case, trailing spaces and
 * accents and PostgreSQL's does not, so each variant is on its OWN Person: it is storable on both engines.
 *
 * | Person | Account         | Stored role keys                             |
 * |--------|-----------------|----------------------------------------------|
 * | 1 Ada  | active          | platform_administrator                       |
 * | 2 Gus  | active          | guardian, platform_administrator             |
 * | 3 Guy  | active          | guardian                                     |
 * | 4 Ines | invited         | guardian                                     |
 * | 5 Dora | disabled        | guardian                                     |
 * | 6 Nora | none            | guardian                                     |
 * | 7      | active          | Guardian                                     |
 * | 8      | active          | GUARDIAN                                     |
 * | 9      | active          | `guardian ` (trailing space)                 |
 * | 10     | active          | guardián                                     |
 * | 11     | active          | Guardian-Full                                |
 * | 12 Mina| active          | none                                         |
 * | 13 Walt| none            | none                                         |
 */
final class HistoricalFixture
{
    /** Role assignments whose key is exactly `guardian`, by id: the rows the rename must move. Written out, not computed from the data. */
    public const array RENAMED = [2, 4, 5, 6, 7];

    /** Every capability an exact `guardian-full` holder has after the upgrade, as the runbook lists them, sorted. Written out, not derived from the catalog. */
    public const array GUARDIAN_BUNDLE = [
        'console.access',
        'crm.people.manage',
        'crm.people.view',
        'discussions.participate',
        'discussions.view',
        'guardians.view',
        'resources.manage',
        'resources.view',
        'volunteers.manage',
        'volunteers.view',
    ];

    /** Person numbers that have an Account, and the Account's status. */
    public const array ACCOUNTS = [
        1 => 'active', 2 => 'active', 3 => 'active', 4 => 'invited', 5 => 'disabled',
        7 => 'active', 8 => 'active', 9 => 'active', 10 => 'active', 11 => 'active', 12 => 'active',
    ];

    /** The 14 migrations after v0.3.0, in the order they run. A new migration changes what an upgrade does: add it here after checking it against the fixture. */
    public const array UPGRADE_MIGRATIONS = [
        '2026_10_01_000001_create_contact_profiles_table.php',
        '2026_10_01_000002_create_contact_methods_table.php',
        '2026_10_01_000003_create_contact_tags_tables.php',
        '2026_10_02_000001_create_contact_interactions_table.php',
        '2026_10_02_000002_create_discussions_table.php',
        '2026_10_02_000003_create_discussion_messages_table.php',
        '2026_10_04_000001_create_resource_categories_table.php',
        '2026_10_04_000002_create_resource_packs_tables.php',
        '2026_10_04_000003_create_resource_cards_tables.php',
        '2026_10_04_000004_create_resource_assets_table.php',
        '2026_10_09_000001_create_person_relationships_tables.php',
        '2026_10_09_000002_rename_guardian_role_key.php',
        '2026_10_09_000003_create_sourced_role_grants_table.php',
        '2026_10_11_000001_add_provenance_to_contact_methods.php',
    ];

    /** The tables those migrations create. `contact_methods` is among them, so the 14th migration only adds columns to a table that is new. */
    public const array NEW_TABLES = [
        'contact_interactions', 'contact_methods', 'contact_profiles', 'contact_tag_assignments', 'contact_tags', 'discussion_messages', 'discussions',
        'person_relationship_field_values', 'person_relationship_role_provisions', 'person_relationship_status_changes', 'person_relationships',
        'resource_assets', 'resource_card_audiences', 'resource_cards', 'resource_categories', 'resource_pack_audiences', 'resource_packs',
        'sourced_role_grants',
    ];

    /** The TOTP secret of Account 1, made for this run; the test decrypts the stored ciphertext after the upgrade and compares. */
    private static string $totpSecret = '';

    public static function totpSecret(): string
    {
        return self::$totpSecret;
    }

    /**
     * A fixed ULID: `01jb`, a kind letter, then the number. All of its characters are valid Crockford base32, and it is lower case, as the
     * application writes every ULID (PostgreSQL compares keys exactly, so an upper-case literal would not be the id the code looks up).
     */
    public static function id(string $kind, int $number): string
    {
        return '01jb'.strtolower($kind).str_pad((string) $number, 21, '0', STR_PAD_LEFT);
    }

    public static function person(int $number): string
    {
        return self::id('P', $number);
    }

    public static function account(int $number): string
    {
        return self::id('A', $number);
    }

    public static function assignment(int $number): string
    {
        return self::id('R', $number);
    }

    /** Writes the whole fixture. The schema must be the v0.3.0 one and empty. */
    public static function load(ConnectionInterface $db, Encrypter $encrypter): void
    {
        $at = '2026-09-19 08:00:00';

        $people = [
            1 => 'Ada Admin', 2 => 'Gus Both', 3 => 'Guy Guardian', 4 => 'Ines Invited', 5 => 'Dora Disabled', 6 => 'Nora Noaccount',
            7 => 'Vera Variant', 8 => 'Vic Variant', 9 => 'Val Variant', 10 => 'Gwen Variant', 11 => 'Fay Variant', 12 => 'Mina Member',
            13 => 'Walt Walkin',
        ];
        foreach ($people as $number => $name) {
            $db->table('people')->insert(['id' => self::person($number), 'display_name' => $name, 'created_at' => $at, 'updated_at' => '2026-09-19 08:30:00']);
        }

        $generation = [1 => 3, 3 => 2, 5 => 4];
        foreach (self::ACCOUNTS as $number => $status) {
            $email = 'person'.$number.'@example.test';
            $db->table('accounts')->insert([
                'id' => self::account($number), 'person_id' => self::person($number), 'email' => $number === 3 ? 'Person3@Example.Test' : $email,
                'email_canonical' => $email,
                'email_verified_at' => $status === 'invited' ? null : '2026-09-19 09:00:00',
                'password_hash' => $status === 'invited' ? null : Hash::make(bin2hex(random_bytes(16))),
                'password_updated_at' => $status === 'invited' ? null : '2026-09-19 09:00:00',
                'status' => $status, 'security_generation' => $generation[$number] ?? 1,
                'disabled_at' => $status === 'disabled' ? '2026-09-28 17:45:00' : null,
                'last_login_at' => $status === 'invited' ? null : '2026-09-27 10:15:00',
                'created_at' => $at, 'updated_at' => '2026-09-27 10:15:00',
            ]);
        }

        // Roles. The granter is Account 1, except the first: the platform's own bootstrap leaves it null.
        $roles = [
            1 => [1, 'platform_administrator'], 2 => [2, 'guardian'], 3 => [2, 'platform_administrator'], 4 => [3, 'guardian'],
            5 => [4, 'guardian'], 6 => [5, 'guardian'], 7 => [6, 'guardian'], 8 => [7, 'Guardian'], 9 => [8, 'GUARDIAN'],
            10 => [9, 'guardian '], 11 => [10, 'guardián'], 12 => [11, 'Guardian-Full'],
        ];
        foreach ($roles as $number => [$person, $key]) {
            $db->table('role_assignments')->insert([
                'id' => self::assignment($number), 'person_id' => self::person($person), 'role_key' => $key,
                'granted_by_account_id' => $number === 1 ? null : self::account(1), 'granted_at' => '2026-09-21 09:'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).':00',
            ]);
        }

        // Invitations: one pending, one that lapsed unaccepted, two accepted; both channels.
        $invitations = [
            1 => [4, '2026-12-31 00:00:00', null, 'email'],
            2 => [4, '2026-09-26 00:00:00', null, 'operator'],
            3 => [5, '2026-10-02 00:00:00', '2026-09-25 14:00:00', 'operator'],
            4 => [3, '2026-10-02 00:00:00', '2026-09-24 11:30:00', 'email'],
        ];
        foreach ($invitations as $number => [$account, $expires, $accepted, $channel]) {
            $db->table('account_invitations')->insert([
                'id' => self::id('N', $number), 'account_id' => self::account($account), 'token_hash' => hash('sha256', random_bytes(32)),
                'expires_at' => $expires, 'accepted_at' => $accepted, 'invited_by_account_id' => self::account(1), 'channel' => $channel,
            ]);
        }

        $db->table('password_reset_tokens')->insert([
            ['email' => 'person3@example.test', 'token' => hash('sha256', random_bytes(32)), 'created_at' => '2026-09-29 07:00:00'],
            ['email' => 'nobody@example.test', 'token' => hash('sha256', random_bytes(32)), 'created_at' => '2026-09-29 07:05:00'],
        ]);

        // Multi-factor: two enrolled factors (one used, one never), one enrolment still pending. The ciphertext is the framework's, made now
        // with the test key; the recovery codes are digests of random codes, one of them used.
        self::$totpSecret = self::randomBase32Secret();
        $db->table('account_totp_factors')->insert([
            ['id' => self::id('T', 1), 'account_id' => self::account(1), 'secret_ciphertext' => $encrypter->encryptString(self::$totpSecret), 'pending_secret_ciphertext' => null,
                'pending_started_at' => null, 'enrolled_at' => '2026-09-20 12:00:00', 'last_used_step' => 59_234_567, 'created_at' => '2026-09-20 11:55:00', 'updated_at' => '2026-09-27 10:15:00'],
            ['id' => self::id('T', 2), 'account_id' => self::account(2), 'secret_ciphertext' => $encrypter->encryptString(self::randomBase32Secret()), 'pending_secret_ciphertext' => null,
                'pending_started_at' => null, 'enrolled_at' => '2026-09-22 08:00:00', 'last_used_step' => null, 'created_at' => '2026-09-22 07:58:00', 'updated_at' => '2026-09-22 08:00:00'],
            ['id' => self::id('T', 3), 'account_id' => self::account(3), 'secret_ciphertext' => null, 'pending_secret_ciphertext' => $encrypter->encryptString(self::randomBase32Secret()),
                'pending_started_at' => '2026-09-28 09:00:00', 'enrolled_at' => null, 'last_used_step' => null, 'created_at' => '2026-09-28 09:00:00', 'updated_at' => '2026-09-28 09:00:00'],
        ]);
        foreach ([[1, 1, '2026-09-26 16:20:00'], [2, 1, null], [3, 1, null], [4, 2, null], [5, 2, null]] as [$number, $account, $used]) {
            $db->table('account_recovery_codes')->insert([
                'id' => self::id('C', $number), 'account_id' => self::account($account), 'code_hash' => hash('sha256', random_bytes(16)), 'used_at' => $used, 'created_at' => '2026-09-20 12:01:00',
            ]);
        }

        // Raw session rows: two signed in, one a guest, with a payload that is neither empty nor plain ASCII.
        $db->table('sessions')->insert([
            ['id' => str_pad('fixture-session-1', 40, 'x'), 'user_id' => self::account(1), 'ip_address' => '203.0.113.10', 'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) fixture',
                'payload' => base64_encode(serialize(['_token' => 'fixture', 'login' => self::account(1), 'note' => "caf\u{e9}"])), 'last_activity' => 1_790_000_000],
            ['id' => str_pad('fixture-session-2', 40, 'x'), 'user_id' => self::account(2), 'ip_address' => '2001:db8::2', 'user_agent' => null,
                'payload' => base64_encode(serialize(['_token' => 'fixture', 'login' => self::account(2)])), 'last_activity' => 1_790_000_100],
            ['id' => str_pad('fixture-session-3', 40, 'x'), 'user_id' => null, 'ip_address' => null, 'user_agent' => null,
                'payload' => base64_encode('guest'), 'last_activity' => 1_790_000_200],
        ]);

        // Membership: open-ended, bounded (not over for decades), expired, revoked, and both sources.
        $db->table('membership_grants')->insert([
            ['id' => self::id('M', 1), 'person_id' => self::person(3), 'starts_at' => '2026-09-23 00:00:00', 'ends_at' => null, 'source' => 'operator', 'source_reference' => null,
                'granted_by_account_id' => self::account(1), 'revoked_at' => null, 'revoked_by_account_id' => null, 'created_at' => '2026-09-23 10:00:00'],
            ['id' => self::id('M', 2), 'person_id' => self::person(12), 'starts_at' => '2026-09-24 00:00:00', 'ends_at' => '2099-12-31 23:59:59', 'source' => 'operator', 'source_reference' => 'ref-1',
                'granted_by_account_id' => self::account(1), 'revoked_at' => null, 'revoked_by_account_id' => null, 'created_at' => '2026-09-24 10:00:00'],
            ['id' => self::id('M', 3), 'person_id' => self::person(12), 'starts_at' => '2024-01-01 00:00:00', 'ends_at' => '2025-01-01 00:00:00', 'source' => 'luma_legacy', 'source_reference' => 'luma-0001',
                'granted_by_account_id' => null, 'revoked_at' => null, 'revoked_by_account_id' => null, 'created_at' => '2026-09-24 10:05:00'],
            ['id' => self::id('M', 4), 'person_id' => self::person(13), 'starts_at' => '2026-09-23 00:00:00', 'ends_at' => null, 'source' => 'operator', 'source_reference' => null,
                'granted_by_account_id' => self::account(1), 'revoked_at' => '2026-09-30 12:00:00', 'revoked_by_account_id' => self::account(1), 'created_at' => '2026-09-23 10:10:00'],
            ['id' => self::id('M', 5), 'person_id' => self::person(13), 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => null, 'source' => 'luma_legacy', 'source_reference' => 'luma-0002',
                'granted_by_account_id' => null, 'revoked_at' => null, 'revoked_by_account_id' => null, 'created_at' => '2026-10-01 08:00:00'],
        ]);

        // The audit trail: grants, sign-ins (one failed, from nobody), an invitation, a disablement, an MFA enrolment.
        $event = static fn (int $number, string $at, string $type, ?int $actor, ?int $person, ?int $account, ?string $ip, string $outcome, ?string $context): array => [
            'id' => self::id('E', $number), 'occurred_at' => $at, 'type' => $type, 'actor_account_id' => $actor === null ? null : self::account($actor), 'actor_client_id' => null,
            'subject_person_id' => $person === null ? null : self::person($person), 'subject_account_id' => $account === null ? null : self::account($account),
            'ip' => $ip, 'user_agent' => $ip === null ? null : 'Mozilla/5.0 fixture', 'outcome' => $outcome, 'context' => $context,
        ];
        $db->table('security_events')->insert([
            $event(1, '2026-09-21 09:00:00', 'role.granted', 1, 2, 2, '203.0.113.10', 'success', '{"role":"guardian"}'),
            $event(2, '2026-09-21 09:00:01', 'role.granted', 1, 2, 2, '203.0.113.10', 'success', '{"role":"platform_administrator"}'),
            $event(3, '2026-09-21 09:00:02', 'role.granted', 1, 3, 3, '203.0.113.10', 'success', '{"role":"guardian"}'),
            $event(4, '2026-09-27 10:15:00', 'authentication.succeeded', 3, 3, 3, '2001:db8::1', 'success', null),
            $event(5, '2026-09-27 10:16:00', 'authentication.failed', null, null, null, '198.51.100.7', 'failure', '{"reason":"bad_credentials"}'),
            $event(6, '2026-09-28 17:45:00', 'account.disabled', 1, 5, 5, '203.0.113.10', 'success', null),
            $event(7, '2026-09-20 12:00:00', 'mfa.enabled', 1, 1, 1, '203.0.113.10', 'success', null),
            $event(8, '2026-09-22 08:00:00', 'account.invited', 1, 4, 4, '203.0.113.10', 'success', '{"channel":"email"}'),
        ]);
    }

    /** A 32-character base32 TOTP secret, new each run. */
    private static function randomBase32Secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        foreach (str_split(bin2hex(random_bytes(32)), 2) as $byte) {
            $secret .= $alphabet[hexdec($byte) % 32];
            if (strlen($secret) === 32) {
                break;
            }
        }

        return $secret;
    }
}
