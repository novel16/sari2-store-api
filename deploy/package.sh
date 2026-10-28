#!/usr/bin/env bash
set -euo pipefail
: "${RUNNER_TEMP:?}"
stage=$(mktemp -d "$RUNNER_TEMP/api-stage.XXXXXX")
trap 'rm -rf "$stage"' EXIT
git archive HEAD | tar -x -C "$stage"
cd "$stage"
rm -rf storage bootstrap/cache .github .codex deploy tests
mkdir -p bootstrap/cache
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts
find . -maxdepth 1 -name '.env*' -delete
tar --dereference -czf "$RUNNER_TEMP/api-release.tar.gz" .
