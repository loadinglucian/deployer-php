#!/usr/bin/env bash

#
# Supervisor Sync Playbook - Ubuntu/Debian Only
#
# Synchronize supervisor configurations from local inventory to server
# ----
#
# This playbook manages supervisor program configurations for a site.
# It generates INI config files in /etc/supervisor/conf.d/ for each
# supervisor defined in the inventory, removes orphaned configs, and
# triggers supervisor to reload.
#
# Supervisor scripts must exist in the site's repository at .deployer/supervisors/
# The playbook only generates config files - supervisord handles script execution.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE   - Output file path (YAML)
#   DEPLOYER_DISTRO        - Server distribution (ubuntu|debian)
#   DEPLOYER_PERMS         - Permissions (root|sudo|none)
#   DEPLOYER_SITE_DOMAIN   - Site domain for supervisor naming
#   DEPLOYER_SUPERVISORS   - JSON array of supervisor objects
#
# Supervisor JSON Format:
#   [
#     {
#       "program": "horizon",
#       "script": "horizon.sh",
#       "autostart": true,
#       "autorestart": true,
#       "stopwaitsecs": 3600,
#       "numprocs": 1
#     }
#   ]
#
# Returns YAML with:
#   - status: success
#   - supervisors_synced: {count}
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
[[ -z $DEPLOYER_SUPERVISORS ]] && echo "Error: DEPLOYER_SUPERVISORS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

SITE_ROOT="/home/deployer/sites/${DEPLOYER_SITE_DOMAIN}"
CURRENT_PATH="${SITE_ROOT}/current"
RUNNER_PATH="${SITE_ROOT}/runner.sh"
CONF_DIR="/etc/supervisor/conf.d"

# ----
# JSON Parsing
# ----

#
# Parse supervisors JSON and validate format
#
# Returns: Number of supervisors parsed, sets SUPERVISOR_COUNT and PROGRAM_NAMES array

parse_supervisors_json() {
	if ! command -v jq > /dev/null 2>&1; then
		echo "Error: jq is required but not installed" >&2
		exit 1
	fi

	if ! echo "$DEPLOYER_SUPERVISORS" | jq empty 2> /dev/null; then
		echo "Error: Invalid DEPLOYER_SUPERVISORS JSON" >&2
		exit 1
	fi

	SUPERVISOR_COUNT=$(echo "$DEPLOYER_SUPERVISORS" | jq 'length')

	# Build array of program names for orphan cleanup
	PROGRAM_NAMES=()
	local i=0
	while ((i < SUPERVISOR_COUNT)); do
		local program
		program=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].program")
		PROGRAM_NAMES+=("${DEPLOYER_SITE_DOMAIN}-${program}")
		((i++))
	done
}

# ----
# Config Generation
# ----

#
# Generate supervisor config content for one program
#
# Arguments:
#   $1 - program name
#   $2 - script filename
#   $3 - autostart (true|false)
#   $4 - autorestart (true|false)
#   $5 - stopwaitsecs
#   $6 - numprocs

generate_supervisor_config() {
	local program=$1
	local script=$2
	local autostart=$3
	local autorestart=$4
	local stopwaitsecs=$5
	local numprocs=$6

	local full_name="${DEPLOYER_SITE_DOMAIN}-${program}"

	cat <<- EOF
		[program:${full_name}]
		command=${RUNNER_PATH} .deployer/supervisors/${script}
		directory=${CURRENT_PATH}
		user=deployer
		autostart=${autostart}
		autorestart=${autorestart}
		stopwaitsecs=${stopwaitsecs}
		numprocs=${numprocs}
		stdout_logfile=/var/log/supervisor/${full_name}.log
		stderr_logfile=/var/log/supervisor/${full_name}.log
	EOF
}

# ----
# Config Management
# ----

#
# Write supervisor config files for all programs

write_supervisor_configs() {
	if ((SUPERVISOR_COUNT == 0)); then
		echo "→ No supervisors to configure..."
		return 0
	fi

	echo "→ Writing ${SUPERVISOR_COUNT} supervisor config(s)..."

	local i=0
	while ((i < SUPERVISOR_COUNT)); do
		local program script autostart autorestart stopwaitsecs numprocs
		local config_content config_file

		program=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].program")
		script=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].script")
		autostart=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].autostart")
		autorestart=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].autorestart")
		stopwaitsecs=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].stopwaitsecs")
		numprocs=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].numprocs")

		config_content=$(generate_supervisor_config "$program" "$script" "$autostart" "$autorestart" "$stopwaitsecs" "$numprocs")
		config_file="${CONF_DIR}/${DEPLOYER_SITE_DOMAIN}-${program}.conf"

		echo "→ Writing config for ${DEPLOYER_SITE_DOMAIN}-${program}..."

		if ! echo "$config_content" | run_cmd tee "$config_file" > /dev/null; then
			echo "Error: Failed to write ${config_file}" >&2
			exit 1
		fi

		((i++))
	done
}

#
# Remove orphaned config files for this domain
#
# Removes any {domain}-*.conf files that are not in the current config list

cleanup_orphaned_configs() {
	echo "→ Checking for orphaned configs..."

	# List existing configs for this domain
	local existing_configs
	existing_configs=$(run_cmd find "$CONF_DIR" -maxdepth 1 -name "${DEPLOYER_SITE_DOMAIN}-*.conf" -type f 2> /dev/null) || existing_configs=""

	if [[ -z $existing_configs ]]; then
		return 0
	fi

	# Check each existing config
	while IFS= read -r config_file; do
		[[ -z $config_file ]] && continue

		local basename="${config_file##*/}"
		local program_name="${basename%.conf}"
		local is_orphan=true

		# Check if this config is in our current list
		for name in "${PROGRAM_NAMES[@]}"; do
			if [[ $program_name == "$name" ]]; then
				is_orphan=false
				break
			fi
		done

		if [[ $is_orphan == true ]]; then
			echo "→ Removing orphaned config: ${basename}..."
			run_cmd rm -f "$config_file" || fail "Failed to remove ${config_file}"
		fi
	done <<< "$existing_configs"
}

# ----
# Supervisor Control
# ----

#
# Reload supervisor to pick up config changes

reload_supervisor() {
	echo "→ Reloading supervisor..."

	if ! run_cmd supervisorctl reread > /dev/null 2>&1; then
		echo "Error: Failed to reread supervisor configs" >&2
		exit 1
	fi

	if ! run_cmd supervisorctl update > /dev/null 2>&1; then
		echo "Error: Failed to update supervisor" >&2
		exit 1
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
		supervisors_synced: ${SUPERVISOR_COUNT}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	parse_supervisors_json
	write_supervisor_configs
	cleanup_orphaned_configs
	reload_supervisor
	write_output
}

main "$@"
