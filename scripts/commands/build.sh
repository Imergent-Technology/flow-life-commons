#!/usr/bin/env bash
# ./flow build: production build of the Guardian Console.
# The platform release build and release packaging are deliberately deferred
# until the release process is designed (production is cPanel, not Docker).
# shellcheck shell=bash

cmd_build() {
    require_docker
    require_setup
    step "Building the Guardian Console (production)"
    node_run npm run build
    node_run npm run verify:build
    ok "Output: apps/guardian-console/dist"
    info "  VITE_API_BASE_URL was baked in as: $(printf 'http://api.flowlife.localhost:%s' "$(gateway_port)") (release builds will set their own)"
}
