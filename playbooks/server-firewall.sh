#!/usr/bin/env bash

#
# Server Firewall Playbook - Ubuntu/Debian Only
#
# Detects UFW status and listening ports, or applies firewall rules.
# ----
#
# Two-mode playbook:
# - detect: Returns UFW status, current rules, and listening ports
# - apply: Configures UFW with specified allowed ports
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE    - Output file path
#   DEPLOYER_MODE           - "detect" or "apply"
#   DEPLOYER_PERMS          - Permissions: root|sudo
#
# Apply mode additional variables:
#   DEPLOYER_SSH_PORT       - SSH port from server config (required)
#   DEPLOYER_ALLOWED_PORTS  - Comma-separated list of ports to allow (required)
#
# Returns YAML with (detect mode):
#   - status: success
#   - ufw_installed: true|false
#   - ufw_active: true|false
#   - ufw_rules: list of port/proto
#   - ports: map of port numbers to process names
#
# Returns YAML with (apply mode):
#   - status: success
#   - ufw_installed: true
#   - ufw_enabled: true
#   - rules_applied: count
#   - ports_allowed: list of ports
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_MODE ]] && echo "Error: DEPLOYER_MODE required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# UFW Detection Functions
# ----

#
# Check if UFW is installed
# Returns: "true" or "false"

check_ufw_installed() {
	if command -v ufw > /dev/null 2>&1; then
		echo "true"
	else
		echo "false"
	fi
}

#
# Check if UFW is active
# Returns: "true" or "false"

check_ufw_active() {
	local status

	status=$(run_cmd ufw status 2> /dev/null | head -n1)

	if [[ $status == "Status: active" ]]; then
		echo "true"
	else
		echo "false"
	fi
}

#
# Get UFW rules as port/proto pairs
# Output: One rule per line in format "port/proto" (e.g., "22/tcp", "80/tcp")

get_ufw_rules() {
	local line port proto

	# Parse ufw status output
	# Example lines:
	#   22/tcp                     ALLOW       Anywhere
	#   80/tcp                     ALLOW       Anywhere
	#   443                        ALLOW       Anywhere
	while read -r line; do
		# Skip header lines and empty lines
		[[ -z $line ]] && continue
		[[ $line =~ ^Status: ]] && continue
		[[ $line =~ ^To ]] && continue
		[[ $line =~ ^-- ]] && continue

		# Skip IPv6 duplicates (lines ending with "(v6)")
		[[ $line =~ \(v6\)$ ]] && continue

		# Extract port/proto from the first field
		# Handles: "22/tcp", "80/tcp", "443" (no proto means both)
		if [[ $line =~ ^([0-9]+)(/[a-z]+)?[[:space:]] ]]; then
			port="${BASH_REMATCH[1]}"
			proto="${BASH_REMATCH[2]:-/tcp}"
			echo "${port}${proto}"
		fi
	done < <(run_cmd ufw status 2> /dev/null)
}

# ----
# UFW Apply Functions
# ----

#
# Validate SSH port is in allowed list (defense in depth)

validate_ssh_port() {
	if [[ -z $DEPLOYER_SSH_PORT ]]; then
		fail "DEPLOYER_SSH_PORT must be set"
	fi

	if [[ -z $DEPLOYER_ALLOWED_PORTS ]]; then
		fail "DEPLOYER_ALLOWED_PORTS must be set"
	fi

	# Check SSH port is in allowed list
	local port
	local found=false
	IFS=',' read -ra ports <<< "$DEPLOYER_ALLOWED_PORTS"
	for port in "${ports[@]}"; do
		if [[ $port == "$DEPLOYER_SSH_PORT" ]]; then
			found=true
			break
		fi
	done

	if [[ $found == false ]]; then
		fail "SSH port $DEPLOYER_SSH_PORT must always be allowed"
	fi
}

#
# Install UFW if not present (F7)

install_ufw_if_missing() {
	if command -v ufw > /dev/null 2>&1; then
		return 0
	fi

	echo "→ Installing UFW..."
	wait_for_dpkg_lock || fail "Timeout waiting for dpkg lock"
	apt_get_with_retry install -y ufw || fail "Failed to install UFW"
}

#
# Allow SSH port (idempotent, silent on existing rule)

allow_ssh_port() {
	run_cmd ufw allow "$DEPLOYER_SSH_PORT/tcp" > /dev/null 2>&1 || true
}

#
# Reset UFW to clear all rules

reset_ufw() {
	echo "→ Resetting UFW rules..."
	run_cmd ufw --force reset > /dev/null 2>&1 || fail "Failed to reset UFW"
}

#
# Set default policies (deny incoming, allow outgoing)

set_default_policies() {
	echo "→ Setting default policies..."
	run_cmd ufw default deny incoming > /dev/null 2>&1 || fail "Failed to set incoming policy"
	run_cmd ufw default allow outgoing > /dev/null 2>&1 || fail "Failed to set outgoing policy"
}

#
# Allow user-selected ports

allow_selected_ports() {
	local port
	IFS=',' read -ra ports <<< "$DEPLOYER_ALLOWED_PORTS"

	echo "→ Allowing selected ports..."
	for port in "${ports[@]}"; do
		# Allow both TCP (explicit) - UFW handles IPv4/IPv6 automatically (F9)
		run_cmd ufw allow "$port/tcp" > /dev/null 2>&1 || fail "Failed to allow port $port"
	done
}

#
# Enable UFW

enable_ufw() {
	echo "→ Enabling firewall..."
	run_cmd ufw --force enable > /dev/null 2>&1 || fail "Failed to enable UFW"
}

# ----
# Mode Functions
# ----

#
# Detect mode: gather UFW status and listening ports

detect_mode() {
	local ufw_installed ufw_active

	echo "→ Checking UFW status..."
	ufw_installed=$(check_ufw_installed)

	if [[ $ufw_installed == "true" ]]; then
		ufw_active=$(check_ufw_active)
	else
		ufw_active="false"
	fi

	echo "→ Detecting listening services..."

	#
	# Write YAML output

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		ufw_installed: ${ufw_installed}
		ufw_active: ${ufw_active}
		ufw_rules:
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi

	# Write UFW rules
	if [[ $ufw_installed == "true" && $ufw_active == "true" ]]; then
		local rule has_rules=false
		while read -r rule; do
			[[ -z $rule ]] && continue
			has_rules=true
			if ! echo "  - ${rule}" >> "$DEPLOYER_OUTPUT_FILE"; then
				echo "Error: Failed to write UFW rule" >&2
				exit 1
			fi
		done < <(get_ufw_rules)

		if [[ $has_rules == false ]]; then
			if ! echo "  []" >> "$DEPLOYER_OUTPUT_FILE"; then
				echo "Error: Failed to write empty UFW rules" >&2
				exit 1
			fi
		fi
	else
		if ! echo "  []" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty UFW rules" >&2
			exit 1
		fi
	fi

	# Write ports section
	if ! echo "ports:" >> "$DEPLOYER_OUTPUT_FILE"; then
		echo "Error: Failed to write ports section header" >&2
		exit 1
	fi

	local port process has_ports=false
	while IFS=: read -r port process; do
		[[ -z $port ]] && continue
		has_ports=true
		if ! echo "  ${port}: ${process}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write port $port" >&2
			exit 1
		fi
	done < <(get_listening_services)

	if [[ $has_ports == false ]]; then
		if ! echo "  {}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write empty ports section" >&2
			exit 1
		fi
	fi
}

#
# Apply mode: configure UFW with specified ports (F7, F8, F9)

apply_mode() {
	# Defense in depth: validate SSH port is in allowed list (F4)
	validate_ssh_port

	# Install UFW if not present (F7)
	install_ufw_if_missing

	# Critical sequence for SSH safety (F8):
	# 1. Allow SSH before reset (prevents lockout if UFW is active)
	echo "→ Securing SSH access..."
	allow_ssh_port

	# 2. Reset UFW to clear all rules
	reset_ufw

	# 3. Re-allow SSH immediately after reset
	allow_ssh_port

	# 4. Set default policies
	set_default_policies

	# 5. Allow user-selected ports (includes SSH)
	allow_selected_ports

	# 6. Enable UFW
	enable_ufw

	#
	# Write YAML output

	local rules_count
	IFS=',' read -ra port_array <<< "$DEPLOYER_ALLOWED_PORTS"
	rules_count=${#port_array[@]}

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		ufw_installed: true
		ufw_enabled: true
		rules_applied: ${rules_count}
		ports_allowed:
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi

	# Write allowed ports list
	local port
	for port in "${port_array[@]}"; do
		if ! echo "  - ${port}" >> "$DEPLOYER_OUTPUT_FILE"; then
			echo "Error: Failed to write port $port to output" >&2
			exit 1
		fi
	done
}

# ----
# Main Execution
# ----

main() {
	case $DEPLOYER_MODE in
		detect)
			detect_mode
			;;
		apply)
			apply_mode
			;;
		*)
			echo "Error: Invalid DEPLOYER_MODE: $DEPLOYER_MODE (expected: detect|apply)" >&2
			exit 1
			;;
	esac
}

main "$@"
