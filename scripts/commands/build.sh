#!/usr/bin/env bash
# ./flow build: production build of the Guardian Console.
# This is only the Console's build. Release artifacts (platform + Console, from an
# exact ref, in isolation) are built by `./flow release build`.
# shellcheck shell=bash

cmd_build() {
    require_docker
    require_setup
    step "Building the Guardian Console (production)"
    node_run npm run build
    node_run npm run verify:build
    ok "Output: apps/guardian-console/dist"
    info "  The Console calls the API at the relative path /api/v1: deploy it on the same origin as the API."
}
