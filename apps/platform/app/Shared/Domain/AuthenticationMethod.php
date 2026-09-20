<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/** How an Actor proved who they are. Only the Guardian Console's session exists so far. */
enum AuthenticationMethod: string
{
    case Session = 'session';
}
