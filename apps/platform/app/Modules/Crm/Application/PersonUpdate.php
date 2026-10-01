<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactProfile;
use App\Modules\Identity\Application\PersonSummary;

/** The result of correcting a Person's name and/or profile: the Person as now named, and their profile if there is one. */
final readonly class PersonUpdate
{
    public function __construct(public PersonSummary $person, public ?ContactProfile $profile) {}
}
