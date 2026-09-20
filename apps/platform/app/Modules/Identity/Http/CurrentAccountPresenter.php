<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\CurrentAccount;
use Carbon\CarbonImmutable;

/**
 * The JSON shape shared by login and `me` (see openapi/openapi.yaml, CurrentAccount).
 *
 * Identity and session information only. There is deliberately no `roles` or
 * `capabilities` field: Access does not exist yet, and an empty or invented value there
 * would be a misleading contract. It is additive when Access arrives.
 */
final readonly class CurrentAccountPresenter
{
    /** @return array<string, array<string, string>> */
    public function present(CurrentAccount $current, CarbonImmutable $authenticatedAt, int $absoluteLifetimeMinutes): array
    {
        return [
            'account' => [
                'id' => $current->actor->accountId->value,
                'email' => $current->email,
            ],
            'person' => [
                'id' => $current->actor->personId->value,
                'display_name' => $current->displayName,
            ],
            'session' => [
                'authenticated_at' => $authenticatedAt->toIso8601ZuluString(),
                'absolute_expires_at' => $authenticatedAt->addMinutes($absoluteLifetimeMinutes)->toIso8601ZuluString(),
            ],
        ];
    }
}
