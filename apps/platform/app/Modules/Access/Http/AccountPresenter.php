<?php

declare(strict_types=1);

namespace App\Modules\Access\Http;

use App\Modules\Access\Application\AccountView;
use App\Modules\Access\Application\ManagedAccountsPage;
use App\Modules\Access\Application\OperatorInvitation;
use App\Modules\Access\Application\RoleAssignmentView;
use App\Modules\Access\Application\RoleDescriptor;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The JSON shapes of the administration API (openapi/openapi.yaml, ManagedAccount and friends).
 *
 * Only administration-relevant fields. Never a password hash, a factor's secret, a recovery code, an invitation
 * token or its hash, a session id, or anything from the audit trail. What an Account HOLDS is called `assignments`,
 * and each carries the catalog's key and words as data for the Console to render.
 */
final readonly class AccountPresenter
{
    /** @return array<string, mixed> */
    public function account(AccountView $view): array
    {
        $account = $view->account;

        return [
            'id' => $account->id->value,
            'person_id' => $account->personId->value,
            'display_name' => $account->displayName,
            'email' => $account->email,
            'email_verified_at' => $this->instant($account->emailVerifiedAt),
            'status' => $account->status,
            'created_at' => $this->instant($account->createdAt),
            'last_login_at' => $this->instant($account->lastLoginAt),
            'disabled_at' => $this->instant($account->disabledAt),
            'mfa' => ['enrolled' => $account->mfaEnrolled, 'recovery_codes_remaining' => $account->recoveryCodesRemaining],
            'invitation' => $account->invitationExpiresAt === null ? null : [
                'expires_at' => $this->instant($account->invitationExpiresAt),
                'expired' => $account->invitationExpiresAt <= now(),
                'delivery' => $account->invitationChannel,
            ],
            'assignments' => array_map(fn (RoleAssignmentView $a): array => [
                'key' => $a->role->key, 'name' => $a->role->name, 'description' => $a->role->description,
                'granted_at' => $this->instant($a->grantedAt),
            ], $view->assignments),
        ];
    }

    /** @return array<string, mixed> */
    public function page(ManagedAccountsPage $page): array
    {
        return [
            'data' => array_map($this->account(...), $page->views),
            'meta' => ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'last_page' => $page->lastPage],
        ];
    }

    /** @return array<string, mixed> */
    public function invitation(OperatorInvitation $invitation): array
    {
        return ['account' => $this->account($invitation->account), 'delivery' => ['status' => $invitation->delivery->value]];
    }

    /**
     * @param  list<RoleDescriptor>  $catalog
     * @return array<string, mixed>
     */
    public function catalog(array $catalog): array
    {
        return ['data' => array_map(fn (RoleDescriptor $r): array => [
            'key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'capabilities' => $r->capabilities,
        ], $catalog)];
    }

    private function instant(?DateTimeInterface $instant): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)->toIso8601ZuluString();
    }
}
