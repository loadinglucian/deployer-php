#!/usr/bin/env bash

set -o pipefail

#
# Gather Server Information
# -------------------------------------------------------------------------------

#
# Detect Linux Distribution
#
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
#
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
#
run_cmd() {
	[[ $DEPLOYER_PERMS == 'root' ]] && "$@" || sudo "$@"
}

#
# Ensure Required Tools are Installed
#
ensure_tools() {
	local distro=$1 perms=$2
	export DEPLOYER_PERMS=$perms

	[[ $perms == 'none' ]] && return 0
	command -v ss > /dev/null 2>&1 && return 0
	command -v netstat > /dev/null 2>&1 && return 0

	case $distro in
		debian)
			export DEBIAN_FRONTEND=noninteractive
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
# Get All Listening Ports
#
get_listening_ports() {
	local cmd port process

	if command -v ss > /dev/null 2>&1; then
		if [[ $DEPLOYER_PERMS == 'root' ]]; then
			cmd='ss'
		elif [[ $DEPLOYER_PERMS == 'sudo' ]]; then
			cmd='sudo ss'
		else
			cmd='ss'
		fi

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
		done < <($cmd -tlnp 2> /dev/null) | sort -t: -k1 -n | uniq

	elif command -v netstat > /dev/null 2>&1; then
		if [[ $DEPLOYER_PERMS == 'root' ]]; then
			cmd='netstat'
		elif [[ $DEPLOYER_PERMS == 'sudo' ]]; then
			cmd='sudo netstat'
		else
			cmd='netstat'
		fi

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
		done < <($cmd -tlnp 2> /dev/null | tail -n +3) | sort -t: -k1 -n | uniq
	fi
}

#
# Main Execution
# ----

main() {
	local distro permissions

	# Gather basic info
	distro=$(detect_distro)
	permissions=$(check_permissions)
	ensure_tools "$distro" "$permissions"

	# Output YAML
	cat <<- EOF
		distro: $distro
		permissions: $permissions
		ports:
	EOF

	# Inline ports formatting
	local port process has_ports=false
	while IFS=: read -r port process; do
		echo "  ${port}: ${process}"
		has_ports=true
	done < <(get_listening_ports)

	if [[ $has_ports == false ]]; then
		echo "  {}"
	fi
}

main "$@"
