<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use RuntimeException;

/**
 * An Http-layer refusal, not an Application one: the Person exists (Application's own
 * `UnknownPerson` already covers "no such Person"), but has never held a grant, so
 * `GET /admin/members/{person}` has nothing to address yet (Package 5's chosen Phase-1
 * semantics — a bare Person is not yet a membership record).
 */
final class MembershipRecordNotFound extends RuntimeException {}
