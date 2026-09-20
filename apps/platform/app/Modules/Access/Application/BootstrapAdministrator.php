<?php

declare(strict_types=1);

namespace App\Modules\Access\Application;

use App\Modules\Access\Domain\RoleAssignment;
use App\Modules\Access\Domain\RoleAssignmentRepository;
use App\Modules\Audit\Application\RecordSecurityEvent;
use App\Modules\Audit\Application\SecurityEventOutcome;
use App\Modules\Identity\Application\EmailAlreadyInUse;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InviteAccount;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * Creates the platform's first administrator, or a recovery administrator (ADR 0020).
 *
 * **Its root of trust is server access**, which is already total, so it confers no new authority
 * and leaves no standing credential. That is why it is a separate use case with its own caller,
 * the console command, and NOT a mode of GrantRole: nothing here asks whether an Actor may do
 * this, and nothing in GrantRole can be told to skip that question. It is not exposed over HTTP.
 *
 * In ONE transaction it creates a Person, an invited Account (NO password: the invitee sets their
 * own, which is a later phase), the single-use invitation, a `platform_administrator` assignment,
 * and the security events. If any write, or any audit write, fails, none of it remains, and no
 * secret was ever handed out: the invitation token is only returned once the transaction has
 * committed, inside BootstrapResult.
 *
 * - **Refuses if an administrator assignment already exists**, however unusable it may be, unless
 *   this is an explicit recovery. Recovery still creates a NEW Person and Account; it never
 *   repurposes or merges an existing one, so an email already in use fails and changes nothing.
 * - The command adds the human friction around recovery (interactive terminal, typed
 *   confirmation); this class enforces the rule itself, in the transaction, so no other caller can
 *   skip it.
 * - Not serialised against a second bootstrap running at the same instant: there is no row to
 *   lock while none exists, and the operator is trusted. The worst outcome is two administrators,
 *   each created by an authorised operator and each fully audited.
 */
final readonly class BootstrapAdministrator
{
    public function __construct(
        private InviteAccount $invite,
        private RoleAssignmentRepository $assignments,
        private RecordSecurityEvent $record,
        private ConnectionInterface $database,
    ) {}

    /**
     * @throws AdministratorAlreadyExists an administrator exists and this is not a recovery
     * @throws EmailAlreadyInUse the address already belongs to an Account; nothing was changed
     */
    public function __invoke(InvitationDetails $details, bool $recovery = false): BootstrapResult
    {
        return $this->database->transaction(function () use ($details, $recovery): BootstrapResult {
            $existing = count($this->assignments->lockHoldersOf(Role::PlatformAdministrator->value));
            if ($existing > 0 && ! $recovery) {
                throw new AdministratorAlreadyExists($existing);
            }

            $invitation = ($this->invite)($details); // Person, invited Account, invitation, account.invited

            $this->assignments->add(RoleAssignment::grant(
                $invitation->personId, Role::PlatformAdministrator->value, null, DateTimeImmutable::createFromInterface(now()),
            ));
            ($this->record)(
                AccessEvent::RoleGranted->value, SecurityEventOutcome::Success,
                null, $invitation->personId, $invitation->accountId, null, null,
                ['role' => Role::PlatformAdministrator->value],
            );
            ($this->record)(
                AccessEvent::AdministratorBootstrapped->value, SecurityEventOutcome::Success,
                null, $invitation->personId, $invitation->accountId, null, null,
                ['recovery' => $recovery, 'existing_administrators' => $existing],
            );

            return new BootstrapResult($invitation, $recovery);
        });
    }
}
