<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Persistence shape of a Person. Internal to Identity: other modules never touch it,
 * they go through Identity's Application layer.
 *
 * @property string $id
 * @property string $display_name
 * @property CarbonInterface $created_at
 * @property CarbonInterface $updated_at
 */
final class PersonRecord extends Model
{
    use HasUlids;

    protected $table = 'people';

    // The domain owns time; the repository writes created_at/updated_at explicitly.
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime'];
    }
}
