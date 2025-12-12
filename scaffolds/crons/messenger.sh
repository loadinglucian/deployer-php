#!/usr/bin/env bash

set -euo pipefail

#
# Symfony Messenger - Consume messages from the queue
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

# Process messages for up to 55 seconds then exit (allows cron to restart fresh)
"${DEPLOYER_PHP}" bin/console messenger:consume async --time-limit=55 --no-interaction
