<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\ContactMethod;
use App\Modules\Crm\Domain\ContactProfile;
use App\Modules\Crm\Domain\ContactTag;
use App\Modules\Identity\Application\PersonSummary;

/**
 * Everything CRM holds about one Person, with the Person's id and name from Identity. Deliberately without
 * Membership, Account or Commons-access state: those stay with their owners and are composed beside this.
 */
final readonly class PersonRecord
{
    /**
     * @param  list<ContactMethod>  $contactMethods
     * @param  list<ContactTag>  $tags
     */
    public function __construct(
        public PersonSummary $person,
        public ?ContactProfile $profile,
        public array $contactMethods,
        public array $tags,
    ) {}
}
