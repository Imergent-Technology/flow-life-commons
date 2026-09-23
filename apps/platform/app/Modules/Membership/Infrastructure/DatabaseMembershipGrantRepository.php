<?php

declare(strict_types=1);

namespace App\Modules\Membership\Infrastructure;

use App\Modules\Membership\Domain\MembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use App\Modules\Membership\Domain\MembershipGrantRepository;
use App\Modules\Membership\Domain\MembershipGrantSource;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Query builder rather than an Eloquent model, as Access's role assignments and Audit's events
 * are: with no model there is nothing whose save() or delete() could rewrite an immutable field
 * or remove a row outside the paths this repository chooses to provide.
 */
final readonly class DatabaseMembershipGrantRepository implements MembershipGrantRepository
{
    private const string TABLE = 'membership_grants';

    public function __construct(private ConnectionInterface $database) {}

    public function add(MembershipGrant $grant): void
    {
        $this->database->table(self::TABLE)->insert([
            'id' => $grant->id->value,
            'person_id' => $grant->personId->value,
            'starts_at' => self::toColumn($grant->startsAt),
            'ends_at' => self::toColumnOrNull($grant->endsAt),
            'source' => $grant->source->value,
            'source_reference' => $grant->sourceReference,
            'granted_by_account_id' => $grant->grantedByAccountId?->value,
            'revoked_at' => null,
            'revoked_by_account_id' => null,
            'created_at' => self::toColumn($grant->createdAt),
        ]);
    }

    public function find(MembershipGrantId $id): ?MembershipGrant
    {
        $row = $this->database->table(self::TABLE)->where('id', $id->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function forPerson(PersonId $personId): array
    {
        $rows = $this->database->table(self::TABLE)
            ->where('person_id', $personId->value)
            ->orderBy('starts_at')->orderBy('id')
            ->get();

        $grants = [];
        foreach ($rows as $row) {
            $grants[] = self::toDomain($row);
        }

        return $grants;
    }

    public function revoke(MembershipGrantId $id, AccountId $revokedBy, DateTimeImmutable $now): bool
    {
        return $this->database->table(self::TABLE)
            ->where('id', $id->value)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => self::toColumn($now),
                'revoked_by_account_id' => $revokedBy->value,
            ]) === 1;
    }

    public function personIdsPage(int $page, int $perPage): array
    {
        $ids = $this->database->table(self::TABLE)
            ->select('person_id')->distinct()
            ->orderBy('person_id')
            ->forPage($page, $perPage)
            ->pluck('person_id');

        $total = $this->database->table(self::TABLE)->distinct()->count('person_id');

        $personIds = [];
        foreach ($ids as $id) {
            assert(is_string($id));
            $personIds[] = PersonId::fromString($id);
        }

        return ['personIds' => $personIds, 'total' => $total];
    }

    public function forPeople(array $personIds): array
    {
        if ($personIds === []) {
            return [];
        }

        $values = array_map(static fn (PersonId $id): string => $id->value, $personIds);
        $rows = $this->database->table(self::TABLE)
            ->whereIn('person_id', $values)
            ->orderBy('person_id')->orderBy('starts_at')->orderBy('id')
            ->get();

        $byPerson = [];
        foreach ($rows as $row) {
            $grant = self::toDomain($row);
            $byPerson[$grant->personId->value][] = $grant;
        }

        return $byPerson;
    }

    private static function toDomain(stdClass $row): MembershipGrant
    {
        assert(is_string($row->id) && is_string($row->person_id) && is_string($row->starts_at));
        assert(is_string($row->source) && is_string($row->created_at));
        assert($row->ends_at === null || is_string($row->ends_at));
        assert($row->source_reference === null || is_string($row->source_reference));
        assert($row->granted_by_account_id === null || is_string($row->granted_by_account_id));
        assert($row->revoked_at === null || is_string($row->revoked_at));
        assert($row->revoked_by_account_id === null || is_string($row->revoked_by_account_id));

        return MembershipGrant::reconstitute(
            MembershipGrantId::fromString($row->id),
            PersonId::fromString($row->person_id),
            self::fromColumn($row->starts_at),
            $row->ends_at === null ? null : self::fromColumn($row->ends_at),
            MembershipGrantSource::from($row->source),
            $row->source_reference,
            $row->granted_by_account_id === null ? null : AccountId::fromString($row->granted_by_account_id),
            $row->revoked_at === null ? null : self::fromColumn($row->revoked_at),
            $row->revoked_by_account_id === null ? null : AccountId::fromString($row->revoked_by_account_id),
            self::fromColumn($row->created_at),
        );
    }

    private static function toColumn(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function toColumnOrNull(?DateTimeImmutable $instant): ?string
    {
        return $instant === null ? null : self::toColumn($instant);
    }

    private static function fromColumn(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
