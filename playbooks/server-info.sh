#!/usr/bin/env bash
#
# Gather Server Information
# ----
# This playbook detects distribution, family, permissions, and listening services.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path (provided automatically)
#
# Returns YAML with:
#   - distro: ubuntu|debian|fedora|centos|rocky|alma|rhel|amazon|unknown
#   - family: debian|fedora|redhat|amazon|unknown
#   - permissions: root|sudo|none
#   - ports: map of port numbers to process names

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

# Validation
if [[ -z $DEPLOYER_OUTPUT_FILE ]]; then
	echo "Error: DEPLOYER_OUTPUT_FILE required"
	exit 1
fi

#
# Detect Linux Distribution
# ----
# Returns: exact distribution name (ubuntu|debian|fedora|centos|rocky|alma|rhel|amazon|unknown)

detect_distro() {
	local distro='unknown'

	if [[ -f /etc/os-release ]]; then
		# Try to get the ID field first for exact distro name
		if grep -q '^ID=' /etc/os-release; then
			distro=$(grep '^ID=' /etc/os-release | cut -d'=' -f2 | tr -d '"' | tr -d "'")

			# Normalize some common variations
			case $distro in
				almalinux) distro='alma' ;;
				rocky | rockylinux) distro='rocky' ;;
				rhel | redhat) distro='rhel' ;;
				amzn) distro='amazon' ;;
			esac
		fi
	elif [[ -f /etc/redhat-release ]]; then
		# Fallback for older systems without /etc/os-release
		if grep -qi 'centos' /etc/redhat-release; then
			distro='centos'
		elif grep -qi 'red hat' /etc/redhat-release; then
			distro='rhel'
		else
			distro='unknown'
		fi
	elif [[ -f /etc/debian_version ]]; then
		# Fallback for Debian systems
		distro='debian'
	fi

	echo "$distro"
}

#
# Detect Distribution Family
# ----
# Returns: debian|fedora|redhat|amazon|unknown

detect_family() {
	local distro=$1
	local family='unknown'

	case $distro in
		ubuntu | debian)
			family='debian'
			;;
		fedora)
			family='fedora'
			;;
		centos | rocky | alma | rhel)
			family='redhat'
			;;
		amazon)
			family='amazon'
			;;
	esac

	echo "$family"
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
	local family=$1 perms=$2
	export DEPLOYER_PERMS=$perms

	# If the command is already installed, return
	command -v ss > /dev/null 2>&1 && return 0
	command -v netstat > /dev/null 2>&1 && return 0

	case $family in
		debian)
			run_cmd apt-get update -q 2> /dev/null
			run_cmd apt-get install -y -q iproute2 2> /dev/null
			;;
		fedora | redhat | amazon)
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
	local distro family permissions

	#
	# Gather basic info

	echo "✓ Detecting distribution..."
	distro=$(detect_distro)
	family=$(detect_family "$distro")

	echo "✓ Checking permissions..."
	permissions=$(check_permissions)

	echo "✓ Cataloging services..."
	ensure_tools "$family" "$permissions"

	#
	# Output YAML to file

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		distro: $distro
		family: $family
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
