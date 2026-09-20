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
    /** @return array<string, array<string, string>|list<string>> */
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
            'capabilities' => $this->sorted($current->capabilities),
            'session' => [
                'authenticated_at' => $authenticatedAt->toIso8601ZuluString(),
                'absolute_expires_at' => $authenticatedAt->addMinutes($absoluteLifetimeMinutes)->toIso8601ZuluString(),
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
