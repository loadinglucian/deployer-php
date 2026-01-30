#!/usr/bin/env bash

set -euo pipefail

#
# Building - Runs immediately after updating the code
# ----
#
# Environment variables provided by Deployer PHP:
#   DEPLOYER_RELEASE_PATH  - Absolute path to the new release directory
#   DEPLOYER_SHARED_PATH   - Absolute path to the shared/ directory
#   DEPLOYER_CURRENT_PATH  - Absolute path to the current/ symlink
#   DEPLOYER_REPO_PATH     - Path to the git cache (bare) repository
#   DEPLOYER_DOMAIN        - Site domain (example.com)
#   DEPLOYER_BRANCH        - Git branch being deployed
#   DEPLOYER_PHP_VERSION   - PHP version selected for this site (e.g. 8.4)
#   DEPLOYER_PHP           - Absolute path to the PHP binary (e.g. /usr/bin/php8.4)
#
# You're automatically in the DEPLOYER_RELEASE_PATH directory at this point:

echo "→ Building release..."

if [[ -f composer.json ]]; then
	echo "→ Installing Composer dependencies..."

	composer_bin="$(command -v composer || true)"
	"${DEPLOYER_PHP}" "${composer_bin}" install --no-interaction --no-dev --optimize-autoloader
fi

if [[ -f package.json ]]; then
	echo "→ Installing frontend dependencies..."
	bun install --frozen-lockfile

	echo "→ Building frontend assets..."
	bun run build
fi
