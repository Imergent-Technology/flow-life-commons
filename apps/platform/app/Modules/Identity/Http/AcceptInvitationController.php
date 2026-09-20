<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http;

use App\Modules\Identity\Application\AcceptInvitation;
use App\Modules\Identity\Application\ClientContext;
use Illuminate\Http\Response;

/**
 * Public and stateless: no session, no cookie, no CSRF (the caller has no account to forge a request
 * as; the token in the body is the credential). It does not sign the caller in. Failures are turned
 * into responses by CredentialProblems, registered in bootstrap/app.php.
 */
final readonly class AcceptInvitationController
{
    public function __invoke(AcceptInvitationRequest $request, AcceptInvitation $accept): Response
    {
        $accept($request->token(), $request->password(), new ClientContext($request->ip(), $request->userAgent()));

        return response()->noContent();
    }
}
