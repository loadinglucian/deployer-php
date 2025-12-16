#!/usr/bin/env bash

#
# Site Cron Sync Playbook - Ubuntu/Debian Only
#
# Synchronize cron jobs from local inventory to server crontab
# ----
#
# This playbook manages cron entries for a site in the deployer user's crontab.
# It uses section markers to isolate each site's crons, allowing multiple sites
# to coexist without interference.
#
# Cron scripts must exist in the site's repository at .deployer/crons/
# The playbook only manages the crontab configuration - scripts are not validated.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE   - Output file path (YAML)
#   DEPLOYER_DISTRO        - Server distribution (ubuntu|debian)
#   DEPLOYER_PERMS         - Permissions (root|sudo|none)
#   DEPLOYER_SITE_DOMAIN   - Site domain for cron working directory
#   DEPLOYER_CRONS         - JSON array of cron objects
#
# Cron JSON Format:
#   [
#     { "script": "scheduler.sh", "schedule": "*/5 * * * *" },
#     { "script": "cleanup.sh", "schedule": "0 0 * * *" }
#   ]
#
# Returns YAML with:
#   - status: success
#   - crons_synced: {count}
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
[[ -z $DEPLOYER_CRONS ]] && echo "Error: DEPLOYER_CRONS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

SITE_ROOT="/home/deployer/sites/${DEPLOYER_SITE_DOMAIN}"
SHARED_PATH="${SITE_ROOT}/shared"

START_MARKER="# DEPLOYER-CRON-START ${DEPLOYER_SITE_DOMAIN}"
END_MARKER="# DEPLOYER-CRON-END ${DEPLOYER_SITE_DOMAIN}"

# ----
# JSON Parsing
# ----

#
# Parse crons JSON and validate format
#
# Returns: Number of crons parsed, sets CRONS array

parse_crons_json() {
	if ! echo "$DEPLOYER_CRONS" | jq empty 2> /dev/null; then
		echo "Error: Invalid DEPLOYER_CRONS" >&2
		exit 1
	fi

	CRON_COUNT=$(echo "$DEPLOYER_CRONS" | jq 'length')
}

# ----
# Crontab Generation
# ----

#
# Generate cron block for a site
#
# Outputs the complete cron section including markers and cron entries
# Environment variables are provided by the site's runner.sh script

generate_cron_block() {
	local cron_block=""
	local runner_path="${SITE_ROOT}/runner.sh"

	# Start marker
	cron_block="${START_MARKER}"$'\n'

	# Generate each cron entry using runner.sh
	local i=0
	while ((i < CRON_COUNT)); do
		local script schedule

		script=$(echo "$DEPLOYER_CRONS" | jq -r ".[$i].script")
		schedule=$(echo "$DEPLOYER_CRONS" | jq -r ".[$i].schedule")

		cron_block+="${schedule} ${runner_path} .deployer/crons/${script} >> /dev/null 2>&1"$'\n'

		((i++))
	done

	# End marker
	cron_block+="${END_MARKER}"

	echo "$cron_block"
}

# ----
# Crontab Management
# ----

#
# Update deployer user's crontab
#
# Removes existing section for this domain and inserts new one

update_crontab() {
	local new_block=$1
	local current_crontab=""
	local updated_crontab=""

	echo "→ Updating crontab for ${DEPLOYER_SITE_DOMAIN}..."

	# Ensure logs directory exists
	if ! run_cmd test -d "${SHARED_PATH}/logs"; then
		run_cmd mkdir -p "${SHARED_PATH}/logs" || fail "Failed to create logs directory"
		run_cmd chown deployer:deployer "${SHARED_PATH}/logs" || fail "Failed to set logs directory ownership"
	fi

	# Get current crontab (ignore error if empty)
	current_crontab=$(run_cmd crontab -l -u deployer 2> /dev/null) || current_crontab=""

	# Remove existing section for this domain
	if [[ -n $current_crontab ]]; then
		# Use awk to remove everything between markers (inclusive)
		updated_crontab=$(echo "$current_crontab" | awk -v start="$START_MARKER" -v end="$END_MARKER" '
			$0 == start { skip = 1; next }
			$0 == end { skip = 0; next }
			!skip { print }
		')
	fi

	# Append new block if we have crons
	if ((CRON_COUNT > 0)); then
		if [[ -n $updated_crontab ]]; then
			updated_crontab="${updated_crontab}"$'\n'"${new_block}"
		else
			updated_crontab="${new_block}"
		fi
	fi

	# Remove trailing blank lines
	updated_crontab=$(echo "$updated_crontab" | sed -e :a -e '/^\s*$/d;N;ba' 2> /dev/null || echo "$updated_crontab")

	# Write updated crontab
	if [[ -n $updated_crontab ]]; then
		if ! echo "$updated_crontab" | run_cmd crontab -u deployer -; then
			echo "Error: Failed to update crontab" >&2
			exit 1
		fi
	else
		# Empty crontab - remove it entirely
		run_cmd crontab -r -u deployer 2> /dev/null || true
	fi
}

# ----
# Output Generation
# ----

#
# Write YAML output file with sync results

write_output() {
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		crons_synced: ${CRON_COUNT}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	parse_crons_json

	local cron_block=""
	if ((CRON_COUNT > 0)); then
		cron_block=$(generate_cron_block)
	fi

	update_crontab "$cron_block"
	write_output
}

main "$@"
