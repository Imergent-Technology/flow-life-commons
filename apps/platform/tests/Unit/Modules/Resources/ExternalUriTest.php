<?php

declare(strict_types=1);

use App\Modules\Resources\Domain\ExternalUri;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\ResourceText;

/*
 * The one rule for an external address (ADR 0037, decision 24). It decides what is safe to STORE and to hand a browser as a
 * link; the platform never fetches it.
 */

it('accepts http and https addresses, storing only the scheme lower-cased', function (string $input, string $stored) {
    expect(ExternalUri::normalise($input))->toBe($stored);
})->with([
    'https' => ['https://example.org', 'https://example.org'],
    'upper-case scheme' => ['HTTPS://Example.org/Path', 'https://Example.org/Path'],
    'http' => ['http://example.org/a', 'http://example.org/a'],
    'port, path, query, fragment' => ['https://example.org:8443/a/b?c=d&e=f#g', 'https://example.org:8443/a/b?c=d&e=f#g'],
    'trailing dot host' => ['https://example.org./', 'https://example.org./'],
    'ip v4' => ['http://127.0.0.1:3000/x', 'http://127.0.0.1:3000/x'],
    'ip v6' => ['http://[2001:db8::1]:8080/x', 'http://[2001:db8::1]:8080/x'],
    'unicode host' => ['https://münchen.example/', 'https://münchen.example/'],
    'unicode path' => ['https://example.org/日本語', 'https://example.org/日本語'],
    'padded' => ["  https://example.org  \n", 'https://example.org'],
]);

it('refuses every scheme but http and https, every relative reference, and every unsafe shape', function (string $input) {
    expect(ExternalUri::normalise($input))->toBeNull();
})->with([
    'javascript' => 'javascript:alert(1)',
    'javascript mixed case' => 'JaVaScRiPt:alert(1)',
    'javascript with a scheme-like prefix' => 'https://example.org@javascript:alert(1)',
    'data' => 'data:text/html;base64,PHNjcmlwdD4=',
    'vbscript' => 'vbscript:msgbox(1)',
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://example.org',
    'mailto in a field address' => 'mailto:team@example.org',
    'relative path' => '/admin/people',
    'protocol relative' => '//example.org',
    'bare host' => 'example.org',
    'scheme with no host' => 'https://',
    'empty authority with a path' => 'https:///path',
    'user information' => 'https://user:pass@example.org',
    'user only' => 'https://user@example.org',
    'space inside' => 'https://exa mple.org',
    'tab inside' => "https://example.org/\ta",
    'newline inside' => "https://example.org/\na",
    'null byte' => "https://example.org/\0",
    'backslash' => 'https://example.org\\@evil.example',
    'angle bracket' => 'https://example.org/<script>',
    'double quote' => 'https://example.org/"onmouseover="x',
    'port zero' => 'https://example.org:0/',
    'port too large' => 'https://example.org:65536/',
    'empty port' => 'https://example.org:/',
    'host starting with a hyphen' => 'https://-example.org/',
    'host with an underscore' => 'https://exa_mple.org/',
    'empty' => '',
    'only whitespace' => '   ',
    'too long' => 'https://example.org/'.str_repeat('a', 2050),
]);

it('allows a mailto address only where a link inside content may have one', function () {
    expect(ExternalUri::normalise('mailto:team@example.org', allowMailto: true))->toBe('mailto:team@example.org')
        ->and(ExternalUri::normalise('MAILTO:team@example.org', allowMailto: true))->toBe('mailto:team@example.org')
        ->and(ExternalUri::normalise('mailto:team@example.org?subject=x', allowMailto: true))->toBeNull()
        ->and(ExternalUri::normalise('mailto:not-an-address', allowMailto: true))->toBeNull()
        ->and(ExternalUri::normalise('mailto:a b@example.org', allowMailto: true))->toBeNull()
        ->and(ExternalUri::normalise('mailto:team@example.org'))->toBeNull();
});

it('answers invalid_uri on the uri field, never echoing the address', function () {
    try {
        ExternalUri::fromInput('javascript:alert(document.cookie)');
        $refused = null;
    } catch (InvalidResourceInput $e) {
        $refused = $e;
    }

    expect($refused)->toBeInstanceOf(InvalidResourceInput::class);
    assert($refused instanceof InvalidResourceInput);
    expect($refused->problem)->toBe('invalid_uri')->and($refused->field)->toBe('uri')->and($refused->getMessage())->not->toContain('alert');
});

it('trims ordinary whitespace from names, titles and summaries but never a control character', function () {
    expect(ResourceText::singleLine("  A title \n", 'title', 'Pack', 200))->toBe('A title')
        ->and(fn () => ResourceText::singleLine("A title\0", 'title', 'Pack', 200))->toThrow(InvalidResourceInput::class)
        ->and(fn () => ResourceText::optionalLine("Summary\0", 'summary', 'Pack', 300))->toThrow(InvalidResourceInput::class)
        ->and(ResourceText::optionalLine("   \n ", 'summary', 'Pack', 300))->toBeNull();
});
