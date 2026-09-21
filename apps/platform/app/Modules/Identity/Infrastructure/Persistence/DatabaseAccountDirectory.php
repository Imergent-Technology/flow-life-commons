<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\AccountDirectory;
use App\Modules\Identity\Application\AccountSearch;
use App\Modules\Identity\Application\ManagedAccount;
use App\Modules\Identity\Application\ManagedAccountPage;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Plain query builder (this is a read model, not an aggregate), a handful of small queries per page and none per
 * row. It selects only the columns an operator may see: never the password hash, a factor's ciphertext, a recovery
 * code digest, an invitation's token hash or a session.
 *
 * Portable: `lower()` and an explicit LIKE escape character behave the same on MariaDB and PostgreSQL, and the order
 * is on `lower(display_name)` because the engines collate a plain VARCHAR differently.
 */
final readonly class DatabaseAccountDirectory implements AccountDirectory
{
    private const string ESCAPE = '!';

    public function __construct(private ConnectionInterface $database) {}

    public function search(AccountSearch $search): ManagedAccountPage
    {
        $search = $search->bounded();
        $query = $this->base();
        $this->narrow($query, $search);

        $total = (clone $query)->count();
        $rows = $query
            ->orderByRaw('lower(p.display_name) asc')->orderBy('a.id')
            ->forPage($search->page, $search->perPage)
            ->get();

        return new ManagedAccountPage($this->hydrate(array_values($rows->all())), $search->page, $search->perPage, $total);
    }

    public function find(AccountId $id): ?ManagedAccount
    {
        $rows = $this->base()->where('a.id', $id->value)->get()->all();

        return $this->hydrate(array_values($rows))[0] ?? null;
    }

    private function base(): Builder
    {
        return $this->database->table('accounts as a')
            ->join('people as p', 'p.id', '=', 'a.person_id')
            ->select([
                'a.id', 'a.person_id', 'p.display_name', 'a.email', 'a.email_verified_at', 'a.status',
                'a.created_at', 'a.last_login_at', 'a.disabled_at',
            ]);
    }

    private function narrow(Builder $query, AccountSearch $search): void
    {
        if ($search->status !== null) {
            $query->where('a.status', $search->status);
        }

        $fragment = $search->query === null ? '' : mb_strtolower(trim($search->query));
        if ($fragment !== '') {
            $like = '%'.str_replace([self::ESCAPE, '%', '_'], [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'], $fragment).'%';
            $query->where(function (Builder $either) use ($like): void {
                $either->whereRaw('lower(a.email_canonical) like ? escape \''.self::ESCAPE.'\'', [$like])
                    ->orWhereRaw('lower(p.display_name) like ? escape \''.self::ESCAPE.'\'', [$like]);
            });
        }
    }

    /**
     * @param  list<\stdClass>  $rows
     * @return list<ManagedAccount>
     */
    private function hydrate(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static function (\stdClass $row): string {
            assert(is_string($row->id));

            return $row->id;
        }, $rows);

        $enrolled = $this->database->table('account_totp_factors')->whereIn('account_id', $ids)->whereNotNull('secret_ciphertext')->pluck('account_id')->all();
        $remaining = [];
        foreach ($this->database->table('account_recovery_codes')->whereIn('account_id', $ids)->whereNull('used_at')
            ->groupBy('account_id')->selectRaw('account_id, count(*) as remaining')->get() as $count) {
            assert(is_string($count->account_id) && is_numeric($count->remaining));
            $remaining[$count->account_id] = (int) $count->remaining;
        }
        // The outstanding invitation: the one that never got accepted. Latest expiry wins (there is normally one).
        $invitations = [];
        foreach ($this->database->table('account_invitations')->whereIn('account_id', $ids)->whereNull('accepted_at')
            ->orderBy('expires_at')->get(['account_id', 'expires_at', 'channel']) as $invitation) {
            assert(is_string($invitation->account_id) && is_string($invitation->expires_at) && is_string($invitation->channel));
            $invitations[$invitation->account_id] = [$this->instant($invitation->expires_at), $invitation->channel];
        }

        $accounts = [];
        foreach ($rows as $row) {
            assert(is_string($row->id) && is_string($row->person_id) && is_string($row->display_name) && is_string($row->email));
            assert(is_string($row->status) && is_string($row->created_at));
            $isEnrolled = in_array($row->id, $enrolled, true);
            $accounts[] = new ManagedAccount(
                AccountId::fromString($row->id), PersonId::fromString($row->person_id), $row->display_name, $row->email,
                $this->instantOrNull($row->email_verified_at), $row->status, $this->instant($row->created_at),
                $this->instantOrNull($row->last_login_at), $this->instantOrNull($row->disabled_at),
                $isEnrolled, $isEnrolled ? ($remaining[$row->id] ?? 0) : 0,
                ($invitations[$row->id] ?? [null, null])[0], ($invitations[$row->id] ?? [null, null])[1],
            );
        }

        return $accounts;
    }

    private function instant(string $value): DateTimeImmutable
    {
        return Utc::fromColumn(CarbonImmutable::parse($value, 'UTC'));
    }

    private function instantOrNull(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? $this->instant($value) : null;
    }
}
