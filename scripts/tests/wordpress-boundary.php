<?php

/*
 * WordPress holds no Commons authority (Membership Foundation, Work Package 7). Run by `./flow check repo`
 * (scripts/commands/check.sh), in the platform image, against the repository mounted read-only. It lives here, not in the
 * platform's test suite, because the platform container mounts apps/platform only and cannot see the companion.
 *
 * WHAT THIS PINS, AND FOR HOW LONG. It asserts a Phase-1 FACT: the companion is still the skeleton, with no Commons
 * credential, no call to the Commons API, no reading of a WordPress user to forward as identity, no request signing, and
 * no WordPress-to-Commons credentials in the platform's environment templates. That is the current phase boundary, NOT a
 * permanent prohibition: service clients and delegated-person authentication (ADR 0018) are an accepted direction, and
 * when the companion is given one of those, this check is EXPECTED to fail and must be revised deliberately in the same
 * change. What does not change then is the durable rule — WordPress never becomes identity authority, so it never asserts
 * who a person is — and a revised check should keep asserting THAT.
 *
 * The companion is read through PHP's tokenizer, so its header comment (which, rightly, talks about the platform API,
 * identity and authorization) is not mistaken for code. Every rule has a positive control run through the same scanner.
 *
 * Usage: php scripts/tests/wordpress-boundary.php <repository root>
 */

declare(strict_types=1);

$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
$companion = "{$root}/apps/wordpress-companion";
$failures = 0;

function ok(string $message): void
{
    fwrite(STDOUT, "  ok    {$message}\n");
}

function failed(string $message): void
{
    global $failures;
    fwrite(STDERR, "  FAIL  {$message}\n");
    $failures++;
}

/**
 * What a PHP source DOES, as the tokenizer sees it: the functions it calls, the constants it defines, and its string literals.
 *
 * @return array{calls: list<string>, literals: list<string>, defines: list<string>}
 */
function inventory(string $source): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($source),
        fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));
    $calls = $literals = $defines = [];
    foreach ($tokens as $i => $token) {
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            $literals[] = substr($token->text, 1, -1);
        } elseif ($token->is(T_ENCAPSED_AND_WHITESPACE)) {
            $literals[] = $token->text;
        } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && ($tokens[$i + 1] ?? null)?->text === '(') {
            $name = strtolower(ltrim($token->text, '\\'));
            $calls[] = $name;
            if ($name === 'define' && ($tokens[$i + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING) === true) {
                $defines[] = substr($tokens[$i + 2]->text, 1, -1);
            }
        } elseif ($token->is(T_NEW) && ($tokens[$i + 1] ?? null) !== null) {
            $calls[] = 'new '.strtolower(ltrim($tokens[$i + 1]->text, '\\'));
        }
    }

    return ['calls' => $calls, 'literals' => $literals, 'defines' => $defines];
}

/** Each rule: a name, and what in an inventory violates it. */
$rules = [
    'calls the Commons API or any other network endpoint' => fn (array $inv): array => array_merge(
        preg_grep('/^(?:wp_(?:safe_)?remote_\w+|download_url|curl_\w+|fsockopen|pfsockopen|stream_socket_client|new wp_http|new (?:\w+\\\\)*requests?)$/', $inv['calls']) ?: [],
        preg_grep('#^https?://|/api/v1\b|commons\.flowlife#i', $inv['literals']) ?: [],
    ),
    'reads a WordPress user, to forward it as who is acting' => fn (array $inv): array => preg_grep(
        '/^(?:get_current_user_id|wp_get_current_user|wp_get_current_user_id|current_user_can|is_user_logged_in|get_userdata|wp_validate_auth_cookie|wp_parse_auth_cookie)$/',
        $inv['calls'],
    ) ?: [],
    'serves or intercepts a REST route (a way for a request to reach the plugin)' => fn (array $inv): array => array_merge(
        preg_grep('/^register_rest_route$/', $inv['calls']) ?: [],
        preg_grep('/^rest_api_init$/', $inv['literals']) ?: [],
    ),
    'signs, or builds a credential to present, on anyone\'s behalf' => fn (array $inv): array => array_merge(
        preg_grep('/^(?:hash_hmac|openssl_sign|sodium_crypto_sign\w*|password_hash)$/', $inv['calls']) ?: [],
        preg_grep('/^(?:authorization|bearer\b.*|x-(?:person|account|user|actor|wordpress)[\w-]*|person_id|account_id|jwt|hs256|rs256)$/i', $inv['literals']) ?: [],
    ),
    'holds a Commons credential or secret' => fn (array $inv): array => array_merge(
        preg_grep('/secret|token|api_?key|password|client_?id|commons|flowlife_api/i', $inv['defines']) ?: [],
        preg_grep('/^(?:flowlife|commons)_(?:api|client|secret|token|key)\w*$/i', $inv['literals']) ?: [],
    ),
];

// --- 1. The companion is still the skeleton -------------------------------------------------------------------------

$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($companion, FilesystemIterator::SKIP_DOTS)) as $file) {
    $files[] = substr($file->getPathname(), strlen($companion) + 1);
}
sort($files);
$skeleton = ['README.md', 'flowlife-companion.php'];
$files === $skeleton
    ? ok('the companion is the Phase-1 skeleton: '.implode(', ', $files))
    : failed('the companion is no longer the skeleton ('.implode(', ', $files).'). If this is ADR 0018 work, revise this check deliberately.');

// --- 2. No companion code holds or exercises any Commons authority ---------------------------------------------------

$sawRealCode = false;
foreach ($files as $relative) {
    if (! str_ends_with($relative, '.php')) {
        continue;
    }
    $inventory = inventory((string) file_get_contents("{$companion}/{$relative}"));
    $sawRealCode = $sawRealCode || (in_array('defined', $inventory['calls'], true) && in_array('ABSPATH', $inventory['literals'], true));
    foreach ($rules as $rule => $violations) {
        $found = array_values(array_unique($violations($inventory)));
        $found === []
            ? ok("{$relative} never {$rule}")
            : failed("{$relative} {$rule}: ".implode(', ', $found));
    }
}
// Positive control on the real file: the scanner read the plugin's actual code (its ABSPATH guard), not an empty file.
$sawRealCode ? ok('the scan read the plugin\'s real code (its ABSPATH guard)') : failed('the scan did not see the plugin\'s ABSPATH guard: it is not reading real code');

// Positive control on the rules: a planted offender trips every one of them, through the same scanner.
$planted = <<<'PHP'
<?php
define('FLOWLIFE_CLIENT_SECRET', 's3cret');
add_action('rest_api_init', fn () => register_rest_route('flowlife/v1', '/me', []));
$user = wp_get_current_user();
$signature = hash_hmac('sha256', (string) $user->ID, FLOWLIFE_CLIENT_SECRET);
wp_remote_post('https://commons.flowlifeglobal.org/api/v1/admin/members', ['headers' => ['X-Person-Id' => $user->ID, 'Authorization' => 'Bearer ' . $signature]]);
PHP;
$plantedInventory = inventory($planted);
foreach ($rules as $rule => $violations) {
    $violations($plantedInventory) !== []
        ? ok("positive control: a planted offender is caught ({$rule})")
        : failed("positive control: a planted offender is NOT caught ({$rule}): the rule cannot fail");
}
// ...and the plugin header's prose, which names the platform API and authorization, is not code.
$header = inventory("<?php\n/** Identity, authorization and data belong to the platform API: wp_remote_post('https://x/api/v1'). */\n");
$header === ['calls' => [], 'literals' => [], 'defines' => []]
    ? ok('comments are not code: the header\'s explanation trips no rule')
    : failed('the scanner reads comments as code');

// --- 3. The platform's environment templates hold no WordPress-to-Commons credential --------------------------------

foreach (['apps/platform/.env.example', 'apps/platform/.env.production.example'] as $template) {
    $keys = [];
    foreach (file("{$root}/{$template}") ?: [] as $line) {
        if (preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=/', $line, $m) === 1) { // a setting, not a comment
            $keys[] = $m[1];
        }
    }
    $offending = array_values(preg_grep('/WORDPRESS|^WP_|API_CLIENT|CLIENT_(?:ID|SECRET)|DELEGAT|JWT|HMAC|SIGNING|COMPANION/', $keys) ?: []);
    if (! in_array('APP_KEY', $keys, true)) {
        failed("{$template}: the scan did not see APP_KEY, so it is not reading the real settings");
    } elseif ($offending !== []) {
        failed("{$template} configures a WordPress/client credential: ".implode(', ', $offending));
    } else {
        ok("{$template} configures no WordPress or client credential (".count($keys).' settings read)');
    }
}
$plantedKeys = ['APP_KEY', 'WORDPRESS_COMMONS_CLIENT_SECRET'];
preg_grep('/WORDPRESS|^WP_|API_CLIENT|CLIENT_(?:ID|SECRET)|DELEGAT|JWT|HMAC|SIGNING|COMPANION/', $plantedKeys) !== []
    ? ok('positive control: a planted WordPress credential setting is caught')
    : failed('positive control: a planted WordPress credential setting is NOT caught');

if ($failures > 0) {
    fwrite(STDERR, "wordpress-boundary: {$failures} failure(s)\n");
    exit(1);
}
fwrite(STDOUT, "wordpress-boundary: WordPress holds no Commons authority (Phase 1)\n");
