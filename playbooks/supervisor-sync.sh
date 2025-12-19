#!/usr/bin/env bash

#
# Supervisor Sync
#
# Synchronizes supervisor program configurations from inventory to server.
#
# Output:
#   status: success
#   supervisors_synced: 2
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
LOGROTATE_DIR="/etc/logrotate.d"

# ----
# JSON Parsing
# ----

#
# Parse supervisors JSON and validate format
#
# Returns: Number of supervisors parsed, sets SUPERVISOR_COUNT and PROGRAM_NAMES array

parse_supervisors_json() {
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
# Write logrotate config files for all programs

write_logrotate_configs() {
	if ((SUPERVISOR_COUNT == 0)); then
		return 0
	fi

	echo "→ Writing ${SUPERVISOR_COUNT} logrotate config(s)..."

	for name in "${PROGRAM_NAMES[@]}"; do
		local logrotate_file="${LOGROTATE_DIR}/supervisor-${name}.conf"

		echo "→ Writing logrotate for ${name}..."

		if ! run_cmd tee "$logrotate_file" > /dev/null <<- EOF; then
			/var/log/supervisor/${name}.log {
			    daily
			    rotate 5
			    maxage 30
			    missingok
			    notifempty
			    compress
			    delaycompress
			    copytruncate
			}
		EOF
			echo "Error: Failed to write ${logrotate_file}" >&2
			exit 1
		fi
	done
}

#
# Remove orphaned config files from a directory
#
# Arguments:
#   $1 - directory to search
#   $2 - file pattern to match
#   $3 - prefix to strip from basename (empty for none)
#   $4 - label for messages (e.g., "config", "logrotate config")

cleanup_orphaned_files() {
	local search_dir=$1
	local pattern=$2
	local prefix=$3
	local label=$4

	echo "→ Checking for orphaned ${label}s..."

	local existing_files
	existing_files=$(run_cmd find "$search_dir" -maxdepth 1 -name "$pattern" -type f 2> /dev/null) || existing_files=""

	if [[ -z $existing_files ]]; then
		return 0
	fi

	while IFS= read -r file_path; do
		[[ -z $file_path ]] && continue

		local basename="${file_path##*/}"
		local program_name="${basename%.conf}"

		# Strip prefix if provided
		if [[ -n $prefix ]]; then
			program_name="${program_name#"$prefix"}"
		fi

		local is_orphan=true

		for name in "${PROGRAM_NAMES[@]}"; do
			if [[ $program_name == "$name" ]]; then
				is_orphan=false
				break
			fi
		done

		if [[ $is_orphan == true ]]; then
			echo "→ Removing orphaned ${label}: ${basename}..."
			run_cmd rm -f "$file_path" || fail "Failed to remove ${file_path}"
		fi
	done <<< "$existing_files"
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
	write_logrotate_configs
	cleanup_orphaned_files "$CONF_DIR" "${DEPLOYER_SITE_DOMAIN}-*.conf" "" "config"
	cleanup_orphaned_files "$LOGROTATE_DIR" "supervisor-${DEPLOYER_SITE_DOMAIN}-*.conf" "supervisor-" "logrotate config"
	reload_supervisor
	write_output
}

main "$@"
