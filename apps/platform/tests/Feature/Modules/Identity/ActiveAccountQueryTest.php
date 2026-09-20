<?php

declare(strict_types=1);

use App\Modules\Identity\Application\ActiveAccountQuery;
use App\Modules\Identity\Domain\AccountRepository;
use App\Shared\Domain\PersonId;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\Identity;

function activeQuery(): ActiveAccountQuery
{
    return app(ActiveAccountQuery::class);
}

/**
 * @param  list<PersonId>  $ids
 * @return list<string>
 */
function values(array $ids): array
{
    $values = array_map(fn (PersonId $p): string => $p->value, $ids);
    sort($values);

    return $values;
}

it('says which people have an account that can still authenticate', function () {
    $active = Identity::savedActiveAccount('active@example.org', name: 'Active');
    $invited = Identity::savedInvitedAccount('invited@example.org');
    $disabled = Identity::savedDisabledAccount('disabled@example.org');
    $contact = Identity::savedPerson('No Account');
    $everyone = [$active->personId, $invited->personId, $disabled->personId, $contact->id, PersonId::generate()];

    foreach (['activePersonIds', 'lockActivePersonIds'] as $method) {
        expect(values(activeQuery()->$method($everyone)))->toBe([$active->personId->value]);
    }
});

it('answers nothing for nobody', function () {
    expect(activeQuery()->activePersonIds([]))->toBe([])->and(activeQuery()->lockActivePersonIds([]))->toBe([]);
});

it('reflects a disable that has just happened', function () {
    $account = Identity::savedActiveAccount();
    expect(values(activeQuery()->lockActivePersonIds([$account->personId])))->toBe([$account->personId->value]);

    app(AccountRepository::class)->save($account->disable(Identity::now()->modify('+1 day')));

    expect(activeQuery()->lockActivePersonIds([$account->personId]))->toBe([]);
});

it('locks the accounts it reads, in id order', function () {
    $a = Identity::savedActiveAccount('a@example.org', name: 'A');
    $b = Identity::savedActiveAccount('b@example.org', name: 'B');
    $sql = [];
    DB::listen(function (QueryExecuted $q) use (&$sql): void {
        $sql[] = strtolower($q->sql);
    });

    activeQuery()->lockActivePersonIds([$a->personId, $b->personId]);
    activeQuery()->activePersonIds([$a->personId, $b->personId]);

    expect($sql[0])->toContain('for update')->toContain('order by')
        ->and($sql[1])->not->toContain('for update');
});
