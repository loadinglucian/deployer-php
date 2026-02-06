#!/usr/bin/env bash

#
# Timezone List Playbook - Ubuntu Only
#
# Lists all available system timezones using timedatectl.
# ----
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_PERMS       - Permissions: root|sudo|none
#
# Returns YAML with:
#   - status: success
#   - timezones: list of available timezone identifiers
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Main Execution
# ----

main() {
	local timezones
	timezones=$(timedatectl list-timezones 2> /dev/null)

	if [[ -z $timezones ]]; then
		echo "Error: Failed to list timezones" >&2
		exit 1
	fi

	# Write output YAML with timezones as a YAML list
	{
		echo "status: success"
		echo "timezones:"
		while IFS= read -r tz; do
			echo "  - ${tz}"
		done <<< "$timezones"
	} > "$DEPLOYER_OUTPUT_FILE"

	if [[ $? -ne 0 ]]; then
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
