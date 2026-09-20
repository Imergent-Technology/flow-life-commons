<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\postJson;

/** Accepting an invitation through the public endpoint, as the Console does. */
final class Invitations
{
    /** @return TestResponse<Response> */
    public static function accept(string $token, string $password = Passwords::STRONG): TestResponse
    {
        return postJson('/api/v1/invitations/accept', ['token' => $token, 'password' => $password, 'password_confirmation' => $password]);
    }
}
