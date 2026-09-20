<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\AccountStatus;
use Carbon\CarbonInterface;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistence shape of an Account, and the model Laravel's authentication machinery
 * will see (ADR: "Account as Authenticatable"). Internal to Identity.
 *
 * This is the seam only. It is not registered as an auth provider, and no guard,
 * login endpoint or session exists yet.
 *
 * @property string $id
 * @property string $person_id
 * @property string $email
 * @property string $email_canonical
 * @property CarbonInterface|null $email_verified_at
 * @property string|null $password_hash
 * @property CarbonInterface|null $password_updated_at
 * @property AccountStatus $status
 * @property CarbonInterface|null $disabled_at
 * @property CarbonInterface|null $last_login_at
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class AccountRecord extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasUlids;

    protected $table = 'accounts';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password_hash'];

    /** The credential lives in password_hash, not Laravel's default "password". */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /** No "remember me": there is no such column, and none is planned. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => AccountStatus::class,
            'email_verified_at' => 'datetime',
            'password_updated_at' => 'datetime',
            'disabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
