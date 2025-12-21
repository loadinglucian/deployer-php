#!/usr/bin/env bash

#
# PHP-FPM Service
#
# Controls PHP-FPM service lifecycle (start/stop/restart) via systemctl.
# Supports operating on multiple PHP versions at once.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE   - Output file path
#   DEPLOYER_DISTRO        - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS         - Permissions: root|sudo|none
#   DEPLOYER_ACTION        - Service action: start|stop|restart
#   DEPLOYER_PHP_VERSIONS  - Comma-separated PHP versions (e.g., "8.3,8.4")
#
# Output:
#   status: success
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_ACTION ]] && echo "Error: DEPLOYER_ACTION required" && exit 1
[[ -z $DEPLOYER_PHP_VERSIONS ]] && echo "Error: DEPLOYER_PHP_VERSIONS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Service Operations
# ----

#
# Execute the requested service action for a single PHP version
#
# Arguments:
#   $1 - PHP version (e.g., "8.4")

execute_action_for_version() {
	local version=$1
	local service="php${version}-fpm"

	case $DEPLOYER_ACTION in
		start | restart)
			echo "→ Running systemctl ${DEPLOYER_ACTION} ${service}..."
			if ! run_cmd systemctl "$DEPLOYER_ACTION" "$service"; then
				echo "Error: Failed to ${DEPLOYER_ACTION} ${service}" >&2
				return 1
			fi
			verify_service_active "$service"
			;;
		stop)
			echo "→ Running systemctl stop ${service}..."
			if ! run_cmd systemctl stop "$service"; then
				echo "Error: Failed to stop ${service}" >&2
				return 1
			fi
			verify_service_stopped "$service"
			;;
		*)
			echo "Error: Invalid action '${DEPLOYER_ACTION}'" >&2
			return 1
			;;
	esac
}

#
# Verify service is active
#
# Arguments:
#   $1 - Service name

verify_service_active() {
	local service=$1
	echo "→ Verifying ${service} is running..."
	local max_wait=10
	local waited=0

	while ! systemctl is-active --quiet "$service" 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: ${service} failed to start" >&2
			return 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

#
# Verify service is stopped
#
# Arguments:
#   $1 - Service name

verify_service_stopped() {
	local service=$1
	echo "→ Verifying ${service} is stopped..."
	local max_wait=10
	local waited=0

	while systemctl is-active --quiet "$service" 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: ${service} failed to stop" >&2
			return 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

# ----
# Main Execution
# ----

main() {
	local failed=0

	# Parse comma-separated versions
	IFS=',' read -ra versions <<< "$DEPLOYER_PHP_VERSIONS"

	for version in "${versions[@]}"; do
		if ! execute_action_for_version "$version"; then
			failed=1
		fi
	done

	if ((failed)); then
		exit 1
	fi

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
