#!/usr/bin/env bash

#
# Server Firewall Playbook - Ubuntu/Debian Only
#
# Configures UFW firewall with specified allowed ports.
# ----
#
# Detection is handled by server-info playbook; this playbook only applies rules.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE    - Output file path
#   DEPLOYER_PERMS          - Permissions: root|sudo
#   DEPLOYER_SSH_PORT       - SSH port from server config
#   DEPLOYER_ALLOWED_PORTS  - Comma-separated list of ports to allow
#
# Returns YAML with:
#   - status: success
#   - ufw_installed: true
#   - ufw_enabled: true
#   - rules_applied: count
#   - ports_allowed: list of ports
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SSH_PORT ]] && echo "Error: DEPLOYER_SSH_PORT required" && exit 1
[[ -z $DEPLOYER_ALLOWED_PORTS ]] && echo "Error: DEPLOYER_ALLOWED_PORTS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# UFW Apply Functions
# ----

#
# Validate SSH port is in allowed list (defense in depth)

validate_ssh_port() {
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
# Install UFW if not present

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
		# Allow both TCP (explicit) - UFW handles IPv4/IPv6 automatically
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
# Main Execution
# ----

main() {
	# Defense in depth: validate SSH port is in allowed list
	validate_ssh_port

	# Install UFW if not present
	install_ufw_if_missing

	# Critical sequence for SSH safety:
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

main "$@"
