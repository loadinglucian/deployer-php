#!/usr/bin/env bash

#
# Supervisor Restart Playbook - Ubuntu/Debian Only
#
# Restart supervisor programs for a site
# ----
#
# This playbook restarts all supervisor programs configured for a site.
# It supports two restart modes:
#   - Graceful (default): Uses supervisorctl restart which sends SIGTERM,
#     waits stopwaitsecs, then SIGKILL if needed
#   - Force: Uses stop + start for immediate restart without waiting
#
# Restart failures are treated as warnings, not fatal errors. This allows
# partial success when some programs fail to restart.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE   - Output file path (YAML)
#   DEPLOYER_DISTRO        - Server distribution (ubuntu|debian)
#   DEPLOYER_PERMS         - Permissions (root|sudo|none)
#   DEPLOYER_SITE_DOMAIN   - Site domain for supervisor naming
#   DEPLOYER_SUPERVISORS   - JSON array of supervisor objects (needs: program)
#
# Optional Environment Variables:
#   DEPLOYER_FORCE         - "true" for immediate restart (default: "false")
#
# Returns YAML with:
#   - status: success
#   - programs_restarted: {count}
#   - programs_failed: {count}
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
[[ -z $DEPLOYER_SUPERVISORS ]] && echo "Error: DEPLOYER_SUPERVISORS required" && exit 1

DEPLOYER_FORCE=${DEPLOYER_FORCE:-false}
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

PROGRAMS_RESTARTED=0
PROGRAMS_FAILED=0

# ----
# JSON Parsing
# ----

#
# Parse supervisors JSON and validate format
#
# Returns: Number of supervisors parsed, sets SUPERVISOR_COUNT

parse_supervisors_json() {
	if ! echo "$DEPLOYER_SUPERVISORS" | jq empty 2> /dev/null; then
		echo "Error: Invalid DEPLOYER_SUPERVISORS JSON" >&2
		exit 1
	fi

	SUPERVISOR_COUNT=$(echo "$DEPLOYER_SUPERVISORS" | jq 'length')
}

# ----
# Restart Operations
# ----

#
# Restart a program gracefully using supervisorctl restart
#
# Arguments:
#   $1 - program name (without domain prefix)
#
# Returns: 0 on success, 1 on failure

restart_program_graceful() {
	local program=$1
	local full_name="${DEPLOYER_SITE_DOMAIN}-${program}"

	echo "-> Restarting ${full_name}..."

	if ! run_cmd supervisorctl restart "$full_name" > /dev/null 2>&1; then
		echo "Warning: Failed to restart ${full_name}" >&2
		return 1
	fi

	return 0
}

#
# Force restart a program using stop + start
#
# Arguments:
#   $1 - program name (without domain prefix)
#
# Returns: 0 on success, 1 on failure

restart_program_force() {
	local program=$1
	local full_name="${DEPLOYER_SITE_DOMAIN}-${program}"

	echo "-> Force restarting ${full_name}..."

	# Stop immediately (ignore errors - program might not be running)
	run_cmd supervisorctl stop "$full_name" > /dev/null 2>&1 || true

	if ! run_cmd supervisorctl start "$full_name" > /dev/null 2>&1; then
		echo "Warning: Failed to start ${full_name}" >&2
		return 1
	fi

	return 0
}

#
# Restart all supervisor programs for the site
#
# Uses graceful or force restart based on DEPLOYER_FORCE setting

restart_all_programs() {
	if ((SUPERVISOR_COUNT == 0)); then
		echo "-> No supervisors to restart..."
		return 0
	fi

	echo "-> Restarting ${SUPERVISOR_COUNT} supervisor program(s)..."

	local i=0
	while ((i < SUPERVISOR_COUNT)); do
		local program
		program=$(echo "$DEPLOYER_SUPERVISORS" | jq -r ".[$i].program")

		if [[ $DEPLOYER_FORCE == "true" ]]; then
			if restart_program_force "$program"; then
				((PROGRAMS_RESTARTED++))
			else
				((PROGRAMS_FAILED++))
			fi
		else
			if restart_program_graceful "$program"; then
				((PROGRAMS_RESTARTED++))
			else
				((PROGRAMS_FAILED++))
			fi
		fi

		((i++))
	done

	if ((PROGRAMS_FAILED > 0)); then
		echo "Warning: ${PROGRAMS_FAILED} supervisor program(s) failed to restart"
	fi
}

# ----
# Output Generation
# ----

#
# Write YAML output file with restart results

write_output() {
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		programs_restarted: ${PROGRAMS_RESTARTED}
		programs_failed: ${PROGRAMS_FAILED}
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
	restart_all_programs
	write_output
}

main "$@"
