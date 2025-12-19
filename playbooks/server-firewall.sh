#!/usr/bin/env bash

#
# Server Firewall
#
# Configures UFW firewall with specified allowed ports.
#
# Output:
#   status: success
#   ufw_installed: true
#   ufw_enabled: true
#   rules_applied: 3
#   ports_allowed:
#     - 22
#     - 80
#     - 443
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

	# 5. Allow user-selected ports
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
