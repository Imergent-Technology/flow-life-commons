#!/usr/bin/env bash
# A REAL Apache regression test for the composed production public surface. Run by `./flow check repo`.
#
# WHY THIS EXISTS. `public-surface.sh` and `ProductionSurfaceTest` pin the .htaccess TEXT, and
# `production-surface.spec.ts` drives the routing contract against Caddy, the production-equivalent
# development gateway. None of the three runs Apache, and Apache is not merely "the same rules in a
# different syntax": `.htaccess` is per-directory context, so a rewrite to a real file (`index.php`)
# triggers Apache's own INTERNAL REDIRECT, which re-runs the whole ruleset as a second pass. Caddy does
# not do this — a matched `handle` block answers the request and nothing re-evaluates — so a rule that
# is correct against Caddy can still be wrong against Apache, and every other layer would keep passing.
#
# This is exactly what happened: with the API/up rewrite as `[L]`, the second pass saw the rewritten
# `/index.php` request, which the maintenance exclusion did not name, so the maintenance rule caught it
# and every `/api` and `/up` request got the HTML responder instead of Laravel while the flag was
# raised. Fixed by using `[END]` instead (public/.htaccess, section 6). This script is the regression
# test for that class of bug: it runs the REAL committed `.htaccess` and `maintenance.php` under a real
# Apache container, not a hand-written proxy for either file.
#
# It also regression-tests the header-duplication fix (`Header onsuccess unset` before `always set`):
# without it, a response Laravel itself answers (which sets these same seven headers) carries each one
# TWICE under Apache, because `Header always set` alone appends rather than replaces.
#
# WHAT IS REAL: apps/platform/public/.htaccess and apps/platform/public/maintenance.php, unmodified,
# under php:8.3-apache (the production PHP version, verified 2026-09-21). WHAT IS STUBBED: the Laravel
# front controller, replaced by a small PHP script that answers with a marker distinguishing "the
# front controller was reached" from "the maintenance responder was reached" and sets the seven policy
# headers with a dummy value, so header replacement (not merely presence) can be proved. This script
# tests ROUTING and HEADER COMPOSITION, not Laravel's actual maintenance or 404 behaviour: that remains
# Caddy's and Laravel's job elsewhere. Host-specific behaviour (LSAPI rather than mod_php, the real
# `mod_rewrite`/`mod_headers` build) is not proved here either; see production-readiness.md.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
HTACCESS="$ROOT/apps/platform/public/.htaccess"
RESPONDER="$ROOT/apps/platform/public/maintenance.php"
APACHE_IMAGE="php:8.3-apache" # matches the verified production PHP version, 8.3.33 (production readiness)

command -v docker >/dev/null 2>&1 || {
    echo "apache-surface: docker not found. This check needs a disposable Apache container." >&2
    exit 1
}
docker info >/dev/null 2>&1 || {
    echo "apache-surface: cannot reach the Docker daemon." >&2
    exit 1
}

WORK="$(mktemp -d)"
NAME="flc-apache-surface-$$-$RANDOM"
FAILURES=0

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT INT TERM

pass() { printf '  ok    %s\n' "$1"; }
fail() {
    printf '  FAIL  %s\n' "$1" >&2
    FAILURES=$((FAILURES + 1))
}

# --- The host-shaped tree ---------------------------------------------------------------------------
# releases/<id>/public/, storage reached by symlink through shared/, current -> releases/<id>: exactly
# the layout docs/runbooks/deployment.md section 0 describes, so the maintenance flag's path
# (%{DOCUMENT_ROOT}/../storage/framework/down) resolves precisely as it does on the real host.
mkdir -p "$WORK/commons/releases/A/public/assets" "$WORK/commons/shared/storage/framework"
cp "$HTACCESS" "$WORK/commons/releases/A/public/.htaccess"
cp "$RESPONDER" "$WORK/commons/releases/A/public/maintenance.php"

# The stub front controller. It answers with a marker (never "Down for maintenance", which only
# maintenance.php emits) so a test can tell the two apart, and it sets the same seven header NAMES
# Laravel's own middleware (ApplyBrowserSecurityHeaders) sets, with a value ('STUB-DUMMY-VALUE') that
# cannot be confused with the real policy — proving replacement, not just that a header was present.
cat >"$WORK/commons/releases/A/public/index.php" <<'PHP'
<?php
header('Content-Security-Policy: STUB-DUMMY-VALUE');
header('X-Content-Type-Options: STUB-DUMMY-VALUE');
header('Referrer-Policy: STUB-DUMMY-VALUE');
header('X-Frame-Options: STUB-DUMMY-VALUE');
header('Permissions-Policy: STUB-DUMMY-VALUE');
header('Cross-Origin-Opener-Policy: STUB-DUMMY-VALUE');
header('Cross-Origin-Resource-Policy: STUB-DUMMY-VALUE');
header('Content-Type: application/json');
$uri = $_SERVER['REQUEST_URI'] ?? '';
// An unknown API path answers 404, like Laravel's own JSON 404, so the maintenance-flag test below can
// prove the front controller is reached (and answers on its own terms) rather than being intercepted.
if (str_contains($uri, 'nope')) {
    http_response_code(404);
}
echo json_encode(['reached' => 'front-controller', 'uri' => $uri]), "\n";
PHP
printf '<!doctype html><title>Shell</title>CONSOLE-SHELL-MARKER' >"$WORK/commons/releases/A/public/index.html"
printf 'body{}' >"$WORK/commons/releases/A/public/assets/app.css"
: >"$WORK/commons/releases/A/public/favicon.ico"
printf 'User-agent: *\n' >"$WORK/commons/releases/A/public/robots.txt"
ln -s ../../shared/storage "$WORK/commons/releases/A/storage"
ln -s releases/A "$WORK/commons/current"
chmod -R a+rX "$WORK/commons"

cat >"$WORK/vhost.conf" <<'CONF'
<VirtualHost *:80>
    ServerName commons.test
    DocumentRoot /srv/commons/current/public
    <Directory /srv/commons>
        Options FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
CONF

# --- Start the disposable Apache -------------------------------------------------------------------
docker run -d --rm --name "$NAME" -p 127.0.0.1:0:80 \
    -v "$WORK/commons:/srv/commons:rw" -v "$WORK/vhost.conf:/etc/apache2/sites-enabled/000-default.conf:ro" \
    --entrypoint bash "$APACHE_IMAGE" -c 'a2enmod rewrite headers >/dev/null && exec apache2-foreground' >/dev/null ||
    { echo "apache-surface: could not start the disposable Apache container ($APACHE_IMAGE)." >&2; exit 1; }

PORT=""
for _ in $(seq 1 20); do
    PORT="$(docker port "$NAME" 80/tcp 2>/dev/null | head -1 | cut -d: -f2)"
    [[ -n "$PORT" ]] && break
    sleep 0.3
done
[[ -n "$PORT" ]] || { echo "apache-surface: could not read the published port." >&2; docker logs "$NAME" >&2 || true; exit 1; }

READY=0
for _ in $(seq 1 30); do
    curl -s -o /dev/null "http://127.0.0.1:$PORT/" && { READY=1; break; }
    sleep 0.3
done
if ((READY == 0)); then
    echo "apache-surface: Apache never answered a request." >&2
    docker logs "$NAME" >&2 || true
    exit 1
fi

# req PATH: fills STATUS, CTYPE and $WORK/body; headers land in $WORK/headers.
req() {
    local resp
    resp="$(curl -s -D "$WORK/headers" -o "$WORK/body" -w '%{http_code} %{content_type}' "http://127.0.0.1:$PORT$1")"
    STATUS="${resp%% *}"
    CTYPE="${resp#* }"
}

# header_count NAME: how many times header NAME appears in the last response (case-insensitive).
header_count() { grep -ic "^$1:" "$WORK/headers" || true; }
header_value() { grep -i "^$1:" "$WORK/headers" | tail -1 | cut -d: -f2- | sed 's/^ //;s/\r$//'; }

check() { # DESC CONDITION_DESCRIPTION ACTUAL EXPECTED
    if [[ "$3" == "$4" ]]; then pass "$1"; else fail "$1 (expected $2 '$4', got '$3')"; fi
}

printf 'apache surface: the front controller answers normally while up\n'
req /
check "/ serves the Console shell" "status" "$STATUS" "200"
req /api/v1/health
check "/api/v1/health reaches the front controller" "status" "$STATUS" "200"
check "…as JSON" "content-type" "$CTYPE" "application/json"
req /up
check "/up reaches the front controller" "status" "$STATUS" "200"

printf 'apache surface: the maintenance flag (H1 regression: [END] not [L] on the API/up rewrite)\n'
touch "$WORK/commons/shared/storage/framework/down"

req /api
check "/api reaches the front controller, not maintenance.php" "status" "$STATUS" "200"
check "…as JSON, not the HTML responder" "content-type" "$CTYPE" "application/json"
if grep -q 'Down for maintenance' "$WORK/body"; then fail "/api was answered by the maintenance responder"; else pass "/api body is not the maintenance page"; fi

req /api/v1/health
check "/api/v1/health reaches the front controller during maintenance" "status" "$STATUS" "200"
check "…as JSON" "content-type" "$CTYPE" "application/json"

req /api/v1/nope
check "/api/v1/nope still reaches the front controller (its own 404)" "status" "$STATUS" "404"
check "…as JSON, not the maintenance page" "content-type" "$CTYPE" "application/json"

req /up
check "/up reaches the front controller during maintenance" "status" "$STATUS" "200"
if grep -q 'reached.:.front-controller' "$WORK/body"; then pass "/up body is the front controller's own"; else fail "/up did not reach the front controller"; fi

req /
check "/ is answered by the maintenance responder" "status" "$STATUS" "503"
check "…as HTML" "content-type" "$CTYPE" "text/html; charset=utf-8"
if grep -q 'Down for maintenance' "$WORK/body"; then pass "/ body is the maintenance page"; else fail "/ body is not the maintenance page"; fi

req /assets/app.css
check "static assets are intercepted too" "status" "$STATUS" "503"
if grep -q 'Down for maintenance' "$WORK/body"; then pass "…and answered with the maintenance page"; else fail "…but not with the maintenance page"; fi

rm "$WORK/commons/shared/storage/framework/down"

printf 'apache surface: exactly one copy of each policy header (M2 regression: onsuccess unset)\n'
HEADERS=(Content-Security-Policy X-Content-Type-Options Referrer-Policy X-Frame-Options Permissions-Policy Cross-Origin-Opener-Policy Cross-Origin-Resource-Policy)
for path in / /api/v1/health /api/v1/nope; do
    req "$path"
    for h in "${HEADERS[@]}"; do
        n="$(header_count "$h")"
        if [[ "$n" != "1" ]]; then
            fail "$path: $h appears $n time(s), expected exactly 1"
        fi
    done
    v="$(header_value Content-Security-Policy)"
    if [[ "$v" == *STUB-DUMMY-VALUE* ]]; then
        fail "$path: Content-Security-Policy carries the front controller's value, not Apache's"
    fi
done
if ((FAILURES == 0)); then
    pass "every response class carries each of the seven headers exactly once, with Apache's value"
fi

if ((FAILURES > 0)); then
    printf '\n%d apache-surface check(s) failed\n' "$FAILURES" >&2
    exit 1
fi
printf '\nAll apache-surface checks passed\n'
