#!/usr/bin/env bash

#
# Site Link Shared Playbook
# ----
# Links shared resources to the current release
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE   - Output file path (YAML)
#   DEPLOYER_DISTRO        - Server distribution (ubuntu|debian)
#   DEPLOYER_PERMS         - Permissions (root|sudo)
#   DEPLOYER_SITE_DOMAIN   - Site domain
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1

export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

SITE_ROOT="/home/deployer/sites/${DEPLOYER_SITE_DOMAIN}"
SHARED_PATH="${SITE_ROOT}/shared"
CURRENT_PATH="${SITE_ROOT}/current"

# Check if current release exists
if [[ ! -L $CURRENT_PATH ]]; then
	fail "No current release found (symlink missing)"
fi

RELEASE_PATH=$(readlink -f "$CURRENT_PATH")
if [[ -z $RELEASE_PATH || ! -d $RELEASE_PATH ]]; then
	fail "Current release path not found: $RELEASE_PATH"
fi

export DEPLOYER_SHARED_PATH="$SHARED_PATH"
export DEPLOYER_RELEASE_PATH="$RELEASE_PATH"

PRESERVE_ENV_VARS="DEPLOYER_SHARED_PATH,DEPLOYER_RELEASE_PATH,DEPLOYER_DISTRO,DEPLOYER_PERMS"

main() {
	link_shared_resources

	if ! cat > "$DEPLOYER_OUTPUT_FILE" << EOF; then
status: success
domain: $DEPLOYER_SITE_DOMAIN
release_path: $RELEASE_PATH
EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"



