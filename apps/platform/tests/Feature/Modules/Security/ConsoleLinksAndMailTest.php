<?php

declare(strict_types=1);

use App\Modules\Identity\Application\DeliverInvitation;
use App\Modules\Identity\Application\InvitationDelivery;
use App\Modules\Identity\Application\InvitationDetails;
use App\Modules\Identity\Application\InviteAccount;
use App\Modules\Identity\Infrastructure\Mail\InvitationMail;
use App\Modules\Identity\Infrastructure\Mail\PasswordResetMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/*
 * The two messages the application sends, and where their links point, pinned for G6-D26
 * (docs/audits/g6/wp6b-dependency-advisories.md): symfony/mime's header-injection advisory (CVE-2026-45070) needs an
 * untrusted MIME parameter or header name, and the host-steering advisories need a link built from the request. Neither is
 * the case; these tests keep it so. They use the real mailer on the test environment's `array` transport.
 */

/** @return list<Email> */
function sentMessages(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();
    assert($transport instanceof Symfony\Component\Mailer\Transport\TransportInterface);

    return array_map(
        static fn ($sent) => $sent->getOriginalMessage(),
        $transport instanceof Illuminate\Mail\Transport\ArrayTransport ? $transport->messages()->all() : [],
    );
}

it('builds an invitation link from the configured application URL, never from the request that triggered it', function () {
    config(['app.url' => 'https://commons.example']);
    Mail::fake();
    $issued = app(InviteAccount::class)->byEmail(InvitationDetails::from('new@example.org', 'New Person'));

    // A request that names another host, as a hostile Host or forwarded header would, is the current request.
    app()->instance('request', Request::create('http://evil.example/api/v1/admin/accounts', 'POST', server: [
        'HTTP_X_FORWARDED_HOST' => 'evil.example',
        'HTTP_ORIGIN' => 'http://evil.example',
    ]));

    expect(app(DeliverInvitation::class)($issued, null, 'issued'))->toBe(InvitationDelivery::Sent);
    Mail::assertSent(InvitationMail::class, function (InvitationMail $mail): bool {
        $body = $mail->render();

        return str_contains($body, 'https://commons.example/accept-invitation#token=') && ! str_contains($body, 'evil.example');
    });
});

it('sends the invitation and the reset message as plain text with a fixed subject, no attachment and no custom header', function () {
    Mail::to('person@example.org')->send(new InvitationMail('https://commons.example/accept-invitation#token=abc', 7));
    Mail::to('person@example.org')->send(new PasswordResetMail('https://commons.example/reset-password#token=abc', 60));

    $messages = sentMessages();
    expect($messages)->toHaveCount(2);

    foreach ($messages as $message) {
        expect($message)->toBeInstanceOf(Email::class)
            ->and($message->getAttachments())->toBe([])
            ->and($message->getHtmlBody())->toBeNull()
            ->and($message->getTextBody())->toBeString();

        // Only the headers the mailer itself writes: no Bcc, Cc, Reply-To, List-*, X-* or any header a value could have named.
        $names = array_map(static fn ($header) => strtolower($header->getName()), iterator_to_array($message->getHeaders()->all(), false));
        expect(array_diff($names, ['from', 'to', 'subject', 'date', 'message-id', 'mime-version']))->toBe([]);
    }

    expect($messages[0]->getSubject())->toBe('You have been invited to Flow Life Commons')
        ->and($messages[1]->getSubject())->toBe('Reset your password');
});

it('refuses a recipient address that carries a line break, and sends nothing', function () {
    $before = count(sentMessages());

    expect(fn () => Mail::to("person@example.org\r\nBcc: attacker@example.net")->send(new InvitationMail('https://commons.example/x', 7)))
        ->toThrow(InvalidArgumentException::class, 'Email addresses may not contain line break characters.');

    expect(count(sentMessages()))->toBe($before);
});
