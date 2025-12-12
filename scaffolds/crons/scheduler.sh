#!/usr/bin/env bash

set -euo pipefail

#
# Laravel Scheduler - Run artisan schedule:run
# ----
#
# This should be executed by cron on the remote server.
#
# Environment variables provided by Deployer PHP:
#   DEPLOYER_DOMAIN        - Site domain (example.com)
#   DEPLOYER_SITE_PATH     - Absolute path to the site root (/home/deployer/sites/{domain})
#   DEPLOYER_CURRENT_PATH  - Absolute path to the current/ symlink
#   DEPLOYER_SHARED_PATH   - Absolute path to the shared/ directory
#   DEPLOYER_PHP           - Absolute path to the PHP binary (e.g. /usr/bin/php8.4)
#

cd "${DEPLOYER_CURRENT_PATH}"

"${DEPLOYER_PHP}" artisan schedule:run --no-interaction
