<?php

declare(strict_types=1);

namespace App\Modules\Membership\Http;

use App\Modules\Membership\Application\RevokeMembershipGrant;
use App\Modules\Membership\Domain\MembershipGrantId;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class RevokeMembershipGrantController
{
    public function __invoke(Request $request, string $grant, RevokeMembershipGrant $revoke, RequestActor $actors): Response
    {
        $revoke($actors->for($request), MembershipGrantId::fromString($grant));

        return response()->noContent();
    }
}
