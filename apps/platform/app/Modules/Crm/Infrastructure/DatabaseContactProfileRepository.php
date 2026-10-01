<?php

declare(strict_types=1);

namespace App\Modules\Crm\Infrastructure;

use App\Modules\Crm\Domain\ContactProfile;
use App\Modules\Crm\Domain\ContactProfileRepository;
use App\Shared\Domain\AccountId;
use App\Shared\Domain\PersonId;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Query builder, like Membership's: no model whose save() could write outside the paths provided. `lock` is
 * the query builder's own portable `insertOrIgnore` (each engine's insert-if-absent, which does not abort a PostgreSQL
 * transaction the way a failed plain insert would), followed by `SELECT ... FOR UPDATE`, so two first writers cannot both fail, and the loser waits for the winner.
 */
final readonly class DatabaseContactProfileRepository implements ContactProfileRepository
{
    private const string TABLE = 'contact_profiles';

    public function __construct(private ConnectionInterface $database) {}

    public function find(PersonId $personId): ?ContactProfile
    {
        $row = $this->database->table(self::TABLE)->where('person_id', $personId->value)->first();

        return $row === null ? null : self::toDomain($row);
    }

    public function lock(PersonId $personId, DateTimeImmutable $now): ContactProfile
    {
        $this->database->table(self::TABLE)->insertOrIgnore([
            'person_id' => $personId->value,
            'how_we_know' => null,
            'affiliation' => null,
            'updated_by_account_id' => null,
            'created_at' => SqlTime::to($now),
            'updated_at' => SqlTime::to($now),
        ]);

        $row = $this->database->table(self::TABLE)->where('person_id', $personId->value)->lockForUpdate()->first();
        assert($row instanceof stdClass);

        return self::toDomain($row);
    }

    public function save(ContactProfile $profile): void
    {
        $this->database->table(self::TABLE)->where('person_id', $profile->personId->value)->update([
            'how_we_know' => $profile->howWeKnow,
            'affiliation' => $profile->affiliation,
            'updated_by_account_id' => $profile->updatedBy?->value,
            'updated_at' => SqlTime::to($profile->updatedAt),
        ]);
    }

    private static function toDomain(stdClass $row): ContactProfile
    {
        assert(is_string($row->person_id) && is_string($row->created_at) && is_string($row->updated_at));
        assert($row->how_we_know === null || is_string($row->how_we_know));
        assert($row->affiliation === null || is_string($row->affiliation));
        assert($row->updated_by_account_id === null || is_string($row->updated_by_account_id));

        return ContactProfile::reconstitute(
            PersonId::fromString($row->person_id),
            $row->how_we_know,
            $row->affiliation,
            $row->updated_by_account_id === null ? null : AccountId::fromString($row->updated_by_account_id),
            SqlTime::from($row->created_at),
            SqlTime::from($row->updated_at),
        );
    }
}
