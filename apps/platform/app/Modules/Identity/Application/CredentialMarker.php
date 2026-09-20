<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Account;

/**
 * A keyed digest of an Account's CURRENT stored credential, for binding a half-finished sign-in to the
 * password that was proved.
 *
 * It is not the password hash, cannot be used to check or derive a password, and is compared only for
 * equality with a fresh digest. It changes whenever the stored credential does (every stored password
 * hash carries its own random salt, so even the same text set again is different), which is the whole
 * point: a pending sign-in that carries the digest from before a reset or change no longer matches.
 * `password_updated_at` cannot do this job, because its resolution is one second.
 */
interface CredentialMarker
{
    public function for(Account $account): string;
}
