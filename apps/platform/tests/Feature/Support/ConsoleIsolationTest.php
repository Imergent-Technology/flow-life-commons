<?php

declare(strict_types=1);

use Tests\Support\Console;
use Tests\Support\Identity;

/*
 * The test harness models browsers honestly: one Console is one browser. It keeps its OWN cookies across its requests,
 * and never sends another Console's. Security tests depend on this to say "an authenticated browser, and a fresh one"
 * in the same test; if a fresh Console could inherit a session, a refusal test could pass as "allowed" for the wrong
 * reason (Laravel keeps request cookies on the test case, merged, for the whole test — see Console::send()).
 *
 * `/api/v1/me` is the probe: the platform's own answer to "who is this request signed in as", 401 for nobody.
 */

it('keeps a browser signed in across its own requests', function () {
    Identity::savedActiveAccount('ada@example.org');
    $ada = new Console;

    $ada->login('ada@example.org', Identity::PASSWORD)->assertOk();

    expect($ada->me()->assertOk()->json('account.email'))->toBe('ada@example.org')
        ->and($ada->me()->assertOk()->json('account.email'))->toBe('ada@example.org');
});

it('gives a fresh browser no session, although another browser in the same test is signed in', function () {
    Identity::savedActiveAccount('ada@example.org');
    $ada = new Console;
    $ada->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $ada->me()->assertOk();

    $stranger = new Console;

    $stranger->me()->assertUnauthorized();
    $stranger->get('/api/v1/admin/accounts')->assertUnauthorized();
    // It holds only what the server gave IT: never Ada's session cookie or her request-forgery token.
    foreach ([Console::SESSION_COOKIE, Console::XSRF_COOKIE] as $name) {
        expect($stranger->cookie($name)?->getValue())->not->toBe($ada->cookie($name)?->getValue(), $name);
    }

    // ...and the stranger's requests did not disturb Ada: she is still signed in, until she signs out herself.
    expect($ada->me()->assertOk()->json('account.email'))->toBe('ada@example.org');
    $ada->logout()->assertNoContent();
    $ada->me()->assertUnauthorized();
});

it('keeps two signed-in browsers apart, each resolving to its own account, however their requests interleave', function () {
    Identity::savedActiveAccount('ada@example.org');
    Identity::savedActiveAccount('grace@example.org', name: 'Grace Hopper');
    $ada = new Console;
    $grace = new Console;

    $ada->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $grace->login('grace@example.org', Identity::PASSWORD)->assertOk();

    expect($ada->me()->json('account.email'))->toBe('ada@example.org')
        ->and($grace->me()->json('account.email'))->toBe('grace@example.org')
        ->and($ada->me()->json('account.email'))->toBe('ada@example.org');

    // Signing one out ends that browser's session only.
    $grace->logout()->assertNoContent();
    $grace->me()->assertUnauthorized();
    expect($ada->me()->assertOk()->json('account.email'))->toBe('ada@example.org');
});

it('gives a newly signed-in browser its own request-forgery token: another browser\'s is refused', function () {
    Identity::savedActiveAccount('ada@example.org');
    Identity::savedActiveAccount('grace@example.org', name: 'Grace Hopper');
    $ada = new Console;
    $grace = new Console;
    $ada->login('ada@example.org', Identity::PASSWORD)->assertOk();
    $grace->login('grace@example.org', Identity::PASSWORD)->assertOk();

    // Grace presenting Ada's token: the session and the token disagree, so the forgery check refuses it...
    $grace->post(Console::API.'/logout', [], ['X-XSRF-TOKEN' => (string) $ada->cookie(Console::XSRF_COOKIE)?->getValue()])
        ->assertStatus(419);
    $grace->me()->assertOk();
    // ...and her own token works, as it does for any browser.
    $grace->logout()->assertNoContent();
});
