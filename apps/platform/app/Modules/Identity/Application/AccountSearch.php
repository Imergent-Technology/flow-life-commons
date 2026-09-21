<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

/**
 * A modest, bounded page of Accounts: which page, how big, and two optional narrowings the operator's list needs.
 * There is no query language: `query` is a plain fragment matched against the email address and the display name,
 * and `status` is one of the three Account statuses.
 */
final readonly class AccountSearch
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(
        public int $page = 1,
        public int $perPage = 25,
        public ?string $query = null,
        public ?string $status = null,
    ) {}

    public function bounded(): self
    {
        return new self(max(1, $this->page), min(self::MAX_PER_PAGE, max(1, $this->perPage)), $this->query, $this->status);
    }
}
