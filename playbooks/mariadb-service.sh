#!/usr/bin/env bash

#
# MariaDB Service Playbook - Ubuntu/Debian Only
#
# Manage MariaDB service lifecycle (start/stop/restart)
# ----
#
# This playbook controls the MariaDB service state using systemctl.
# It supports three actions: start, stop, and restart.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE  - Output file path (YAML)
#   DEPLOYER_DISTRO       - Server distribution (ubuntu|debian)
#   DEPLOYER_PERMS        - Permissions (root|sudo)
#   DEPLOYER_ACTION       - Service action (start|stop|restart)
#
# Returns YAML with:
#   - status: success
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_ACTION ]] && echo "Error: DEPLOYER_ACTION required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Service Operations
# ----

#
# Execute the requested service action
#
# Validates the action and executes the corresponding systemctl command

execute_action() {
	case $DEPLOYER_ACTION in
		start | restart)
			echo "→ Running systemctl ${DEPLOYER_ACTION} mariadb..."
			if ! run_cmd systemctl "$DEPLOYER_ACTION" mariadb; then
				echo "Error: Failed to ${DEPLOYER_ACTION} MariaDB" >&2
				exit 1
			fi
			verify_service_active
			;;
		stop)
			echo "→ Running systemctl stop mariadb..."
			if ! run_cmd systemctl stop mariadb; then
				echo "Error: Failed to stop MariaDB" >&2
				exit 1
			fi
			verify_service_stopped
			;;
		*)
			echo "Error: Invalid action '${DEPLOYER_ACTION}'" >&2
			exit 1
			;;
	esac
}

#
# Verify MariaDB service is active

verify_service_active() {
	echo "→ Verifying MariaDB is running..."
	local max_wait=10
	local waited=0

	while ! systemctl is-active --quiet mariadb 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: MariaDB service failed to start" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

#
# Verify MariaDB service is stopped

verify_service_stopped() {
	echo "→ Verifying MariaDB is stopped..."
	local max_wait=10
	local waited=0

	while systemctl is-active --quiet mariadb 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: MariaDB service failed to stop" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

# ----
# Main Execution
# ----

main() {
	execute_action

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
