#!/usr/bin/env bash

#
# Site Cron Sync
#
# Synchronizes cron jobs from inventory to the deployer user's crontab.
# Each cron script logs to its own file: /var/log/cron/{domain}-{script}.log
#
# Output:
#   status: success
#   crons_synced: 2
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
CRON_LOG_DIR="/var/log/cron"
LOGROTATE_DIR="/etc/logrotate.d"

# Populated by parse_crons_json()
declare -a SCRIPT_NAMES=()
declare -a SCRIPT_BASES=()

START_MARKER="# DEPLOYER-CRON-START ${DEPLOYER_SITE_DOMAIN}"
END_MARKER="# DEPLOYER-CRON-END ${DEPLOYER_SITE_DOMAIN}"

# ----
# JSON Parsing
# ----

#
# Parse crons JSON and validate format
#
# Sets: CRON_COUNT, SCRIPT_NAMES[], SCRIPT_BASES[]

parse_crons_json() {
	if ! echo "$DEPLOYER_CRONS" | jq empty 2> /dev/null; then
		echo "Error: Invalid DEPLOYER_CRONS" >&2
		exit 1
	fi

	CRON_COUNT=$(echo "$DEPLOYER_CRONS" | jq 'length')

	# Build arrays of script names and their base names (without .sh)
	local i=0
	while ((i < CRON_COUNT)); do
		local script
		script=$(echo "$DEPLOYER_CRONS" | jq -r ".[$i].script")
		SCRIPT_NAMES+=("$script")
		SCRIPT_BASES+=("${script%.sh}")
		((i++))
	done
}

# ----
# Crontab Generation
# ----

#
# Generate cron block for a site
#
# Outputs the complete cron section including markers and cron entries
# Each script logs to /var/log/cron/{domain}-{script_base}.log

generate_cron_block() {
	local cron_block=""
	local runner_path="${SITE_ROOT}/runner.sh"

	# Start marker
	cron_block="${START_MARKER}"$'\n'

	# Generate each cron entry using runner.sh
	local i=0
	while ((i < CRON_COUNT)); do
		local schedule script_base script_log
		schedule=$(echo "$DEPLOYER_CRONS" | jq -r ".[$i].schedule")
		script_base="${SCRIPT_BASES[$i]}"
		script_log="${CRON_LOG_DIR}/${DEPLOYER_SITE_DOMAIN}-${script_base}.log"

		cron_block+="${schedule} ${runner_path} .deployer/crons/${SCRIPT_NAMES[$i]} >> ${script_log} 2>&1"$'\n'

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
# Logging Configuration
# ----

#
# Setup cron log directory and per-script log files

setup_cron_logging() {
	echo "→ Setting up cron logging for ${CRON_COUNT} script(s)..."

	# Create cron log directory if it doesn't exist
	if ! run_cmd test -d "$CRON_LOG_DIR"; then
		run_cmd mkdir -p "$CRON_LOG_DIR" || fail "Failed to create cron log directory"
	fi

	# Create per-script log files
	for script_base in "${SCRIPT_BASES[@]}"; do
		local log_file="${CRON_LOG_DIR}/${DEPLOYER_SITE_DOMAIN}-${script_base}.log"

		if ! run_cmd test -f "$log_file"; then
			run_cmd touch "$log_file" || fail "Failed to create ${log_file}"
		fi

		run_cmd chown deployer:deployer "$log_file" || fail "Failed to set ownership on ${log_file}"
		run_cmd chmod 644 "$log_file" || fail "Failed to set permissions on ${log_file}"
	done
}

#
# Write logrotate configs for cron logs (one per script)

write_logrotate_configs() {
	if ((CRON_COUNT == 0)); then
		return 0
	fi

	echo "→ Writing ${CRON_COUNT} logrotate config(s)..."

	for script_base in "${SCRIPT_BASES[@]}"; do
		local log_file="${CRON_LOG_DIR}/${DEPLOYER_SITE_DOMAIN}-${script_base}.log"
		local logrotate_file="${LOGROTATE_DIR}/cron-${DEPLOYER_SITE_DOMAIN}-${script_base}.conf"

		echo "→ Writing logrotate for ${DEPLOYER_SITE_DOMAIN}-${script_base}..."

		if ! run_cmd tee "$logrotate_file" > /dev/null <<- EOF; then
			${log_file} {
			    daily
			    rotate 5
			    maxage 30
			    missingok
			    notifempty
			    compress
			    delaycompress
			    copytruncate
			    su deployer deployer
			}
		EOF
			echo "Error: Failed to write ${logrotate_file}" >&2
			exit 1
		fi
	done
}

#
# Cleanup orphaned logrotate configs for this domain
#
# Removes configs for scripts that are no longer in the inventory

cleanup_orphaned_logrotate_configs() {
	echo "→ Checking for orphaned cron logrotate configs..."

	local pattern="cron-${DEPLOYER_SITE_DOMAIN}-*.conf"
	local prefix="cron-${DEPLOYER_SITE_DOMAIN}-"
	local existing_files
	existing_files=$(run_cmd find "$LOGROTATE_DIR" -maxdepth 1 -name "$pattern" -type f 2> /dev/null) || existing_files=""

	if [[ -z $existing_files ]]; then
		return 0
	fi

	while IFS= read -r file_path; do
		[[ -z $file_path ]] && continue

		local basename="${file_path##*/}"
		local script_base="${basename%.conf}"
		script_base="${script_base#"$prefix"}"

		local is_orphan=true

		for name in "${SCRIPT_BASES[@]}"; do
			if [[ $script_base == "$name" ]]; then
				is_orphan=false
				break
			fi
		done

		if [[ $is_orphan == true ]]; then
			echo "→ Removing orphaned logrotate config: ${basename}..."
			run_cmd rm -f "$file_path" || fail "Failed to remove ${file_path}"
		fi
	done <<< "$existing_files"
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
		setup_cron_logging
		cron_block=$(generate_cron_block)
		write_logrotate_configs
	fi

	# Always check for orphans (handles removed scripts and empty cron list)
	cleanup_orphaned_logrotate_configs

	update_crontab "$cron_block"
	write_output
}

main "$@"
