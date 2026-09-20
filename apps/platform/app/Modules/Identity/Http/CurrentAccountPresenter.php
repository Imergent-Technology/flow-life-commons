<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\CurrentAccount;
use Carbon\CarbonImmutable;

/**
 * The JSON shape shared by login and `me` (see openapi/openapi.yaml, CurrentAccount).
 *
 * Identity, what the account may currently do, and session information. Capabilities are
 * identifiers in a stable (alphabetical) order and are never role names: the client learns
 * what it may do, not which label produced it. There is no `roles` field.
 */
final readonly class CurrentAccountPresenter
{
    /**
     * @param  CarbonImmutable|null  $securityVerifiedAt  when the session last proved password and a second factor
     * @return array<string, array<string, mixed>|list<string>>
     */
    public function present(
        CurrentAccount $current,
        CarbonImmutable $authenticatedAt,
        int $absoluteLifetimeMinutes,
        ?CarbonImmutable $securityVerifiedAt = null,
        int $verificationMinutes = 15,
    ): array {
        $verifiedUntil = $securityVerifiedAt?->addMinutes($verificationMinutes);

        return [
            'account' => [
                'id' => $current->actor->accountId->value,
                'email' => $current->email,
            ],
            'person' => [
                'id' => $current->actor->personId->value,
                'display_name' => $current->displayName,
            ],
            'capabilities' => $this->sorted($current->capabilities),
            'session' => [
                'authenticated_at' => $authenticatedAt->toIso8601ZuluString(),
                'absolute_expires_at' => $authenticatedAt->addMinutes($absoluteLifetimeMinutes)->toIso8601ZuluString(),
            ],
            // Presentation only, and nothing about the factor itself: never a secret, a code or a digest.
            'mfa' => [
                'enrolled' => $current->mfa->enrolled,
                'recovery_codes_remaining' => $current->mfa->recoveryCodesRemaining,
                'security_verified_until' => $verifiedUntil !== null && $verifiedUntil->isFuture() ? $verifiedUntil->toIso8601ZuluString() : null,
            ],
        ];
    }

    /**
     * Alphabetical, so the order never depends on how the catalog happens to be declared.
     *
     * @param  list<string>  $capabilities
     * @return list<string>
     */
    private function sorted(array $capabilities): array
    {
        $unique = array_values(array_unique($capabilities));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
