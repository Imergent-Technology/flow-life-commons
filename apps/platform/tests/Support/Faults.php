<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Audit\Domain\SecurityEvent;
use App\Modules\Audit\Domain\SecurityEventWriter;
use App\Modules\Identity\Domain\Account;
use App\Modules\Identity\Domain\AccountInvitation;
use App\Modules\Identity\Domain\AccountInvitationId;
use App\Modules\Identity\Domain\AccountInvitationRepository;
use App\Modules\Identity\Domain\AccountRepository;
use App\Modules\Identity\Domain\EmailAddress;
use App\Modules\Identity\Domain\InvitationToken;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Makes one specific write fail, so a test can prove that everything written before it is rolled
 * back. Each decorates the real implementation and throws at exactly one point.
 */
final class Faults
{
    /**
     * The Nth audit event (1-based) fails to write, counted across EVERY writer the container hands
     * out: a use case that calls another has its own RecordSecurityEvent, and so its own writer, and
     * a per-instance counter would silently count a different step.
     */
    public static function auditFailsAt(int $nth): void
    {
        $inner = app(SecurityEventWriter::class);
        $seen = new \ArrayObject(['count' => 0]);
        app()->bind(SecurityEventWriter::class, fn () => new class($inner, $nth, $seen) implements SecurityEventWriter
        {
            /** @param  \ArrayObject<string, int>  $seen */
            public function __construct(private SecurityEventWriter $inner, private int $nth, private \ArrayObject $seen) {}

            public function append(SecurityEvent $event): void
            {
                $this->seen['count']++;
                if ($this->seen['count'] === $this->nth) {
                    throw new RuntimeException('audit store unavailable');
                }
                $this->inner->append($event);
            }
        });
    }

    /** Saving an Account fails (the Person has already been written by then). */
    public static function accountSaveFails(): void
    {
        $inner = app(AccountRepository::class);
        app()->instance(AccountRepository::class, new class($inner) implements AccountRepository
        {
            public function __construct(private AccountRepository $inner) {}

            public function save(Account $account): void
            {
                throw new RuntimeException('account write failed');
            }

            public function find(AccountId $id): ?Account
            {
                return $this->inner->find($id);
            }

            public function findForUpdate(AccountId $id): ?Account
            {
                return $this->inner->findForUpdate($id);
            }

            public function findByPersonId(PersonId $personId): ?Account
            {
                return $this->inner->findByPersonId($personId);
            }

            public function findByEmail(EmailAddress $email): ?Account
            {
                return $this->inner->findByEmail($email);
            }
        });
    }

    /** Saving the invitation fails (the Person and Account have already been written). */
    public static function invitationSaveFails(): void
    {
        $inner = app(AccountInvitationRepository::class);
        app()->instance(AccountInvitationRepository::class, new class($inner) implements AccountInvitationRepository
        {
            public function __construct(private AccountInvitationRepository $inner) {}

            public function save(AccountInvitation $invitation): void
            {
                throw new RuntimeException('invitation write failed');
            }

            public function find(AccountInvitationId $id): ?AccountInvitation
            {
                return $this->inner->find($id);
            }

            public function findByToken(InvitationToken $token): ?AccountInvitation
            {
                return $this->inner->findByToken($token);
            }

            public function findByTokenForUpdate(InvitationToken $token): ?AccountInvitation
            {
                return $this->inner->findByTokenForUpdate($token);
            }
        });
    }

    /** Adding the role assignment fails (the Person, Account and invitation have already been written). */
    public static function roleAssignmentFails(): void
    {
        $inner = app(RoleAssignmentRepository::class);
        app()->instance(RoleAssignmentRepository::class, new class($inner) implements RoleAssignmentRepository
        {
            public function __construct(private RoleAssignmentRepository $inner) {}

            public function forPerson(PersonId $personId): array
            {
                return $this->inner->forPerson($personId);
            }

            public function add(RoleAssignment $assignment): void
            {
                throw new RuntimeException('assignment write failed');
            }

            public function remove(PersonId $personId, string $roleKey): bool
            {
                return $this->inner->remove($personId, $roleKey);
            }

            public function holdersOf(string $roleKey): array
            {
                return $this->inner->holdersOf($roleKey);
            }

            public function lockHoldersOf(string $roleKey): array
            {
                return $this->inner->lockHoldersOf($roleKey);
            }
        });
    }

    /** Every row this phase can write, encoded, for asserting that a secret is nowhere in the database. */
    public static function everythingStored(): string
    {
        $all = '';
        foreach (['people', 'accounts', 'account_invitations', 'role_assignments', 'security_events', 'sessions', 'password_reset_tokens'] as $table) {
            $all .= json_encode(DB::table($table)->get()->all(), JSON_THROW_ON_ERROR);
        }

        return $all;
    }

    /** @return array<string, int> row counts of what a bootstrap creates */
    public static function counts(): array
    {
        $counts = [];
        foreach (['people', 'accounts', 'account_invitations', 'role_assignments', 'security_events'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
