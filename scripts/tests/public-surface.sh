#!/usr/bin/env bash
# Drift between the two deployment adapters of ONE public contract. Run by `./flow check repo`.
#
# The production origin is Apache reading apps/platform/public/.htaccess; the production-equivalent
# development origin is Caddy reading infrastructure/docker/caddy/Caddyfile. They cannot share
# syntax, so nothing can compare them line for line — but they can be held to the same CONTRACT, and
# the failure that matters is one of them gaining or losing a rule class the other still has. That is
# invisible to every other check in this repository:
#
#   - the browser suite drives Caddy, and would keep passing if .htaccess lost a rule;
#   - ProductionSurfaceTest reads .htaccess, and cannot see the Caddyfile at all (the platform
#     container mounts apps/platform only, which is why that test says so in a comment).
#
# This script is the only place that reads both files, so it is deliberately about their INTERSECTION
# and nothing else. Behaviour is proved in apps/guardian-console/e2e/production-surface.spec.ts.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
HTACCESS="$ROOT/apps/platform/public/.htaccess"
CADDYFILE="$ROOT/infrastructure/docker/caddy/Caddyfile"
RESPONDER="$ROOT/apps/platform/public/maintenance.php"
WORK="$(mktemp -d)"
FAILURES=0
trap 'rm -rf "$WORK"' EXIT

pass() { printf '  ok    %s\n' "$1"; }
fail() {
    printf '  FAIL  %s\n' "$1" >&2
    FAILURES=$((FAILURES + 1))
}

# The Caddyfile describes two origins. Only the production-equivalent one is bound by this contract:
# the development origin runs Vite under a deliberately weaker policy (ADR 0026), and folding it in
# would either fail every check or force production to be bent to match a dev server.
sed -n '/^(prod_security_headers)/,$p' "$CADDYFILE" >"$WORK/prod-site"

# Comments in both files name the mechanisms that must NOT be used, and explain why. Absence has to
# be asserted against directives, or a file fails for its own explanation.
grep -v '^[[:space:]]*#' "$HTACCESS" >"$WORK/apache-directives" || true
grep -v '^[[:space:]]*#' "$WORK/prod-site" >"$WORK/caddy-directives" || true

printf 'public surface: files\n'
for f in "$HTACCESS" "$CADDYFILE" "$RESPONDER"; do
    if [[ -f "$f" ]]; then pass "present: ${f#"$ROOT/"}"; else fail "missing: ${f#"$ROOT/"}"; fi
done

printf 'public surface: the browser security policy is one policy\n'
# Every `Header always set NAME "VALUE"` in the Apache block, minus HSTS, which is HTTPS-only and
# therefore deliberately absent from the plain-HTTP development origin.
sed -n 's/^[[:space:]]*Header always set \([A-Za-z-]*\) "\(.*\)"$/\1 \2/p' "$WORK/apache-directives" |
    grep -v '^Strict-Transport-Security' | grep -v '"expr=' | LC_ALL=C sort >"$WORK/apache-headers"
sed -n 's/^[[:space:]]*\([A-Z][A-Za-z-]*\) "\(.*\)"$/\1 \2/p' "$WORK/caddy-directives" | LC_ALL=C sort >"$WORK/caddy-headers"

if [[ ! -s "$WORK/apache-headers" ]]; then
    fail "no 'Header always set' directives found in .htaccess"
elif diff -u "$WORK/apache-headers" "$WORK/caddy-headers" >"$WORK/header-diff"; then
    pass "both adapters state the same $(wc -l <"$WORK/apache-headers" | tr -d ' ') headers, with the same values"
else
    fail "the two adapters state different security headers:"
    sed 's/^/        /' "$WORK/header-diff" >&2
fi

if grep -q 'Strict-Transport-Security' "$WORK/apache-directives"; then
    pass "Apache carries HSTS (conditional on HTTPS)"
else
    fail "Apache must carry HSTS for the HTTPS production origin"
fi
if grep -q 'Strict-Transport-Security' "$WORK/caddy-directives"; then
    fail "the plain-HTTP development origin must NOT claim HSTS"
else
    pass "the plain-HTTP development origin does not claim HSTS"
fi

printf 'public surface: both adapters carry every rule class\n'
# NAME <tab> literal that must appear in .htaccess <tab> literal that must appear in the Caddyfile.
# A quoted heredoc, so the regex punctuation below reaches grep -F exactly as written; every literal
# is a substring of the real file rather than a pattern either file is expected to match.
check_pairs() {
    local name apache caddy
    while IFS=$'\t' read -r name apache caddy; do
        [[ -n "$name" ]] || continue
        if ! grep -qF -- "$apache" "$WORK/apache-directives"; then
            fail "$name: missing from .htaccess (looked for: $apache)"
        elif ! grep -qF -- "$caddy" "$WORK/caddy-directives"; then
            fail "$name: missing from the Caddyfile's production-equivalent origin (looked for: $caddy)"
        else
            pass "$name"
        fi
    done
}

check_pairs <<'CONTRACT'
the maintenance flag is Laravel's own storage/framework/down	storage/framework/down -f	storage/framework/down
the maintenance responder is public/maintenance.php	/maintenance.php [L]	maintenance.php
the API is carved out of maintenance	!^/(api($|/)|up$|maintenance\.php$)	not path /api /api/* /up /maintenance.php
the API and /up reach Laravel	"^(api($|/)|up$)" index.php [END]	path /api /api/* /up
the Console shell is the fallback for everything else	/index.html [L]	/index.html
CONTRACT

printf 'public surface: the same private paths are denied by both\n'
check_pairs <<'PRIVATE'
dot-paths, with the ACME challenge exempt	(^|/)\.(?!well-known/)	path_regexp (^|/)\.
the ACME challenge path stays reachable	well-known	well-known
the application source directories	(app|bootstrap|config|database|routes|storage|tests|node_modules|vendor)(/|$)	/app /app/*
vendor	vendor)(/|$)	/vendor /vendor/*
storage	storage|tests	/storage /storage/*
artisan	^(artisan|	/artisan
the Composer manifests	composer\.(json|lock)	/composer.json /composer.lock
release.json, which names the commit	release\.json	/release.json
PRIVATE

printf 'public surface: mechanisms that must not come back\n'
# ErrorDocument/R=503 failed on this host (ADR 0027); Commons is direct to origin and trusts no proxy
# (trust boundaries), so nothing here purges a cache or reads a forwarding header. 'index.php [L]'
# failed on a real Apache container: [L] lets the API/up rewrite's own internal redirect re-run the
# ruleset, and the maintenance rule then catches the rewritten /index.php on that second pass.
for forbidden in ErrorDocument 'R=503' 'index.php [L]' maintenance.html LSCache Sucuri X-Forwarded RemoteIPHeader opcache_reset; do
    if grep -qF -- "$forbidden" "$WORK/apache-directives" || grep -qF -- "$forbidden" "$WORK/caddy-directives"; then
        fail "a deployment adapter reintroduced '$forbidden'"
    else
        pass "absent from both: $forbidden"
    fi
done

printf 'public surface: the responder stays standalone\n'
if grep -qE '\b(require|require_once|include|include_once)\b|vendor/autoload|Illuminate' "$RESPONDER"; then
    fail "public/maintenance.php loads something; it must answer when the release is broken"
else
    pass "public/maintenance.php loads neither Laravel, vendor nor the environment"
fi
for required in 'http_response_code(503)' 'Retry-After: 120' 'Cache-Control: no-store, no-cache, must-revalidate' 'text/html; charset=utf-8'; do
    if grep -qF -- "$required" "$RESPONDER"; then
        pass "the responder sets: $required"
    else
        fail "the responder does not set: $required"
    fi
done

if ((FAILURES > 0)); then
    printf '\n%d public-surface check(s) failed\n' "$FAILURES" >&2
    exit 1
fi
printf '\nAll public-surface checks passed\n'
