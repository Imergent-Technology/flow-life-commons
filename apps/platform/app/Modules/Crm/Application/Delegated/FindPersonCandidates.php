<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Delegated;

use App\Modules\Crm\Domain\ContactMethodKind;
use App\Modules\Crm\Domain\ContactMethodRepository;
use App\Modules\Crm\Domain\InvalidContactInput;
use App\Modules\Identity\Application\FindPeople;
use App\Modules\Identity\Application\PeopleQuery;
use App\Modules\Identity\Application\SearchPeople;

/**
 * The bounded lookup a relationship's intake uses to find a Person who may already exist (ADR 0038, P4).
 *
 * - A query with an `@` is an address: it matches CRM contact emails EXACTLY, by CRM's `search_value`. Never an Account's
 *   login email, which is Identity's and which CRM may not read.
 * - A query of phone characters with at least 7 digits is a phone number: it matches exactly on its digits.
 * - Anything else is a case-insensitive "contains" on the display name.
 *
 * An exact match may be a non-primary method: this lookup is a deliberately separate contract from the directory's primary-only
 * search, and a partial address or number matches nothing. At most ten candidates and a `truncated` flag. Each candidate is
 * an id, a display name and what matched. Never a contact value, a relationship, a profile, a tag or an Account.
 *
 * It authorizes nothing and counts the query's length for no one: its caller bounds that.
 */
final readonly class FindPersonCandidates
{
    public const int LIMIT = 10;

    public const int MIN_PHONE_DIGITS = 7;

    public function __construct(
        private SearchPeople $search,
        private FindPeople $people,
        private ContactMethodRepository $methods,
    ) {}

    public function __invoke(string $query): PersonCandidates
    {
        $query = trim($query);
        if (str_contains($query, '@')) {
            return $this->byContact('email', $this->emailValues($query));
        }
        $digits = preg_replace('/[^0-9]/', '', $query) ?? '';
        if (strlen($digits) >= self::MIN_PHONE_DIGITS && preg_match('/^[0-9+\s().\-]+$/', $query) === 1) {
            return $this->byContact('phone', [ContactMethodKind::Phone->value => array_values(array_unique([$digits, '+'.$digits]))]);
        }

        $page = ($this->search)(new PeopleQuery($query, null, null, 1, self::LIMIT + 1));
        $shown = array_slice($page->people, 0, self::LIMIT);

        return new PersonCandidates(
            array_map(static fn ($person): PersonCandidate => new PersonCandidate($person->id, $person->displayName, 'display_name'), $shown),
            $page->total > self::LIMIT,
        );
    }

    /**
     * @return array<string, list<string>> the search values to match, by kind
     */
    private function emailValues(string $query): array
    {
        try {
            $display = ContactMethodKind::Email->display($query);
        } catch (InvalidContactInput) {
            return [ContactMethodKind::Email->value => []]; // not an address: it matches nothing, and says nothing about why
        }

        return [ContactMethodKind::Email->value => [ContactMethodKind::Email->searchValue($display)]];
    }

    /**
     * @param  array<string, list<string>>  $searchValues
     */
    private function byContact(string $matchedOn, array $searchValues): PersonCandidates
    {
        $found = [];
        foreach ($searchValues as $kind => $values) {
            foreach ($values as $value) {
                foreach ($this->methods->personIdsWithSearchValue(ContactMethodKind::from($kind), $value, self::LIMIT + 1) as $id) {
                    $found[$id->value] = $id;
                }
            }
        }
        ksort($found);
        $ids = array_values($found);
        $shown = array_slice($ids, 0, self::LIMIT);
        $names = $shown === [] ? [] : ($this->people)($shown);

        $candidates = [];
        foreach ($shown as $id) {
            $summary = $names[$id->value] ?? null;
            if ($summary !== null) {
                $candidates[] = new PersonCandidate($id, $summary->displayName, $matchedOn);
            }
        }

        return new PersonCandidates($candidates, count($ids) > self::LIMIT);
    }
}
