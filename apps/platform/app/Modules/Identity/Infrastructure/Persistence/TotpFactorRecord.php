<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistence shape of a TotpFactor. Internal to Identity.
 *
 * @property string $id
 * @property string $account_id
 * @property string|null $secret_ciphertext
 * @property string|null $pending_secret_ciphertext
 * @property CarbonInterface|null $pending_started_at
 * @property CarbonInterface|null $enrolled_at
 * @property int|null $last_used_step
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class TotpFactorRecord extends Model
{
    use HasUlids;

    protected $table = 'account_totp_factors';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> the ciphertext is opaque, but nothing has a reason to serialise it */
    protected $hidden = ['secret_ciphertext', 'pending_secret_ciphertext'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_started_at' => 'datetime', 'enrolled_at' => 'datetime',
            'created_at' => 'datetime', 'updated_at' => 'datetime', 'last_used_step' => 'integer',
        ];
    }
}
