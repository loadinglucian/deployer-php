#!/usr/bin/env bash

set -euo pipefail

#
# Cron Script - Run scheduled tasks for supported frameworks
# ----
#
# This should be executed by cron on the remote server.
#
# Environment variables provided by runner script:
#   DEPLOYER_RELEASE_PATH  - Absolute path to the current release directory
#   DEPLOYER_SHARED_PATH   - Absolute path to the shared/ directory
#   DEPLOYER_CURRENT_PATH  - Absolute path to the current/ symlink
#   DEPLOYER_DOMAIN        - Site domain (example.com)
#   DEPLOYER_BRANCH        - Git branch currently deployed
#   DEPLOYER_PHP           - Absolute path to the PHP binary (e.g. /usr/bin/php8.4)
#

cd "${DEPLOYER_CURRENT_PATH}"

# ----
# Framework Detection
# ----

framework=""

if [[ -f artisan ]]; then
	framework="laravel"
elif [[ -f bin/console ]]; then
	framework="symfony"
elif [[ -f spark ]]; then
	framework="codeigniter"
fi

# ----
# Scheduled Tasks
# ----

if [[ $framework == "laravel" ]]; then
	"${DEPLOYER_PHP}" artisan schedule:run --no-interaction
elif [[ $framework == "symfony" ]]; then
	# Process messages for up to 55 seconds then exit (allows cron to restart fresh)
	"${DEPLOYER_PHP}" bin/console messenger:consume async --time-limit=55 --no-interaction
else
	echo "Unsupported framework for cron.sh. Customize this script for your app." >&2
	exit 1
fi
