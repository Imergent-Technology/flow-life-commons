<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistence shape of an AccountInvitation. Internal to Identity.
 *
 * @property string $id
 * @property string $account_id
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 * @property string|null $invited_by_account_id
 */
final class AccountInvitationRecord extends Model
{
    use HasUlids;

    protected $table = 'account_invitations';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];
    }
}
