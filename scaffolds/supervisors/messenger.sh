#!/usr/bin/env bash

set -euo pipefail

#
# Symfony Messenger - Consume messages from transport
# ----
#
# This should be executed by supervisord on the remote server.
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

#
# Why exec is required:
#   Without exec, the process tree looks like:
#     supervisord -> bash (tracked) -> php (actual worker)
#   Supervisord only tracks bash. When it sends SIGTERM to stop the program,
#   the signal goes to bash, not PHP. PHP never gets a chance to gracefully
#   finish the current message before shutting down.
#
#   With exec, bash is replaced by PHP:
#     supervisord -> php (tracked directly)
#   Now SIGTERM goes directly to PHP, allowing graceful shutdown within
#   the stopwaitsecs window (default 3600s).
#
# The --time-limit flag ensures graceful restart (matches default stopwaitsecs=3600).
#
exec "${DEPLOYER_PHP}" bin/console messenger:consume async --time-limit=3600
