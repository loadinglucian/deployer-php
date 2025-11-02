#!/usr/bin/env bash
#
# Gather Server Information
# ----
# This playbook detects distribution, permissions, and listening services.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path (provided automatically)
#
# Returns YAML with:
#   - distro: debian|redhat|amazon|unknown
#   - permissions: root|sudo|none
#   - ports: map of port numbers to process names

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

# Validation
if [[ -z $DEPLOYER_OUTPUT_FILE ]]; then
	echo "Error: DEPLOYER_OUTPUT_FILE environment variable is required"
	exit 1
fi

#
# Detect Linux Distribution
# ----
# Returns: debian|redhat|amazon|unknown

detect_distro() {
	local distro='unknown'

	if [[ -f /etc/os-release ]]; then
		if grep -qi 'amazon' /etc/os-release; then
			distro='amazon'
		elif grep -qi 'debian\|ubuntu' /etc/os-release; then
			distro='debian'
		elif grep -qi 'fedora\|centos\|rhel' /etc/os-release; then
			distro='redhat'
		fi
	elif [[ -f /etc/redhat-release ]]; then
		distro='redhat'
	elif [[ -f /etc/debian_version ]]; then
		distro='debian'
	fi

	echo "$distro"
}

#
# Check User Permissions
# ----
# Returns: root|sudo|none

check_permissions() {
	if [[ $EUID -eq 0 ]]; then
		echo 'root'
	elif sudo -n true 2> /dev/null; then
		echo 'sudo'
	else
		echo 'none'
	fi
}

#
# Execute Command with Appropriate Permissions
# ----

run_cmd() {
	if [[ $DEPLOYER_PERMS == 'root' ]]; then
		"$@"
	else
		sudo -n "$@"
	fi
}

#
# Ensure Required Tools are Installed
# ----

ensure_tools() {
	local distro=$1 perms=$2
	export DEPLOYER_PERMS=$perms

	# If the command is already installed, return
	command -v ss > /dev/null 2>&1 && return 0
	command -v netstat > /dev/null 2>&1 && return 0

	case $distro in
		debian)
			run_cmd apt-get update -q 2> /dev/null
			run_cmd apt-get install -y -q iproute2 2> /dev/null
			;;
		redhat | amazon)
			run_cmd yum install -y -q iproute 2> /dev/null \
				|| run_cmd dnf install -y -q iproute 2> /dev/null
			;;
	esac
}

#
# Get All Listening Services
# ----

get_listening_services() {
	local port process

	if command -v ss > /dev/null 2>&1; then
		while read -r line; do
			[[ $line =~ ^State ]] && continue
			[[ ! $line =~ LISTEN ]] && continue

			if [[ $line =~ :([0-9]+)[[:space:]] ]]; then
				port="${BASH_REMATCH[1]}"
				if [[ $line =~ users:\(\(\"([^\"]+)\" ]]; then
					process="${BASH_REMATCH[1]}"
				else
					process="unknown"
				fi
				echo "${port}:${process}"
			fi
		done < <(run_cmd ss -tlnp 2> /dev/null) | sort -t: -k1 -n | uniq

	elif command -v netstat > /dev/null 2>&1; then
		while read -r proto recvq sendq local foreign state program; do
			[[ $state != "LISTEN" ]] && continue

			if [[ $local =~ :([0-9]+)$ ]]; then
				port="${BASH_REMATCH[1]}"
				if [[ $program =~ /(.+)$ ]]; then
					process="${BASH_REMATCH[1]}"
				else
					process="unknown"
				fi
				echo "${port}:${process}"
			fi
		done < <(run_cmd netstat -tlnp 2> /dev/null | tail -n +3) | sort -t: -k1 -n | uniq
	fi
}

#
# Main Execution
# ----

main() {
	local distro permissions

	#
	# Gather basic info

	echo "✓ Detecting distribution..."
	distro=$(detect_distro)

	echo "✓ Checking permissions..."
	permissions=$(check_permissions)

	echo "✓ Cataloging services..."
	ensure_tools "$distro" "$permissions"

	#
	# Output YAML to file

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		distro: $distro
		permissions: $permissions
		ports:
	EOF
		echo "Error: Failed to write $DEPLOYER_OUTPUT_FILE" >&2
		exit 1
	fi

	local port process has_ports=false
	while IFS=: read -r port process; do
		if ! echo "  ${port}: ${process}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write services list to $DEPLOYER_OUTPUT_FILE" >&2
			exit 1
		fi
		has_ports=true
	done < <(get_listening_services)

	if [[ $has_ports == false ]]; then
		if ! echo "  {}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty services lists to $DEPLOYER_OUTPUT_FILE" >&2
			exit 1
		fi
	fi
}

main "$@"
