<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** Identifies a Person: the canonical human. Business modules reference this. */
final readonly class PersonId extends UlidIdentifier {}
