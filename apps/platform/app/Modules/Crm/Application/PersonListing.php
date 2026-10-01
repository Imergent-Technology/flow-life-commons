<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Identity\Application\PersonSummary;

/** One row of the People directory: the Person, their primary email and phone as recorded, and their tags. */
final readonly class PersonListing
{
    /** @param  list<ContactTag>  $tags */
    public function __construct(
        public PersonSummary $person,
        public ?string $primaryEmail,
        public ?string $primaryPhone,
        public array $tags,
    ) {}
}
