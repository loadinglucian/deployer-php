#!/usr/bin/env bash

#
# Timezone Configuration Playbook - Ubuntu Only
#
# Configures the system timezone using timedatectl.
# ----
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_PERMS       - Permissions: root|sudo|none
#   DEPLOYER_TIMEZONE    - IANA timezone (e.g., America/New_York)
#
# Returns YAML with:
#   - status: success
#   - timezone: configured timezone
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_TIMEZONE ]] && echo "Error: DEPLOYER_TIMEZONE required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Main Execution
# ----

main() {
	local current_tz
	current_tz=$(timedatectl show --property=Timezone --value 2> /dev/null || echo "")

	if [[ $current_tz == "$DEPLOYER_TIMEZONE" ]]; then
		echo "Timezone already set to ${DEPLOYER_TIMEZONE}"
	else
		echo "→ Setting timezone to ${DEPLOYER_TIMEZONE}..."
		if ! run_cmd timedatectl set-timezone "$DEPLOYER_TIMEZONE"; then
			echo "Error: Failed to set timezone to ${DEPLOYER_TIMEZONE}" >&2
			exit 1
		fi
	fi

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		timezone: ${DEPLOYER_TIMEZONE}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
