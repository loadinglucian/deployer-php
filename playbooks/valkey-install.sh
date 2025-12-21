#!/usr/bin/env bash

#
# Valkey Installation
#
# Installs Valkey server and configures password authentication.
# Adds official Valkey repository if package is not available in system repos.
#
# Output (fresh install):
#   status: success
#   valkey_pass: Ek3jF8mNpQ2rS5tV
#
# Output (already installed):
#   status: success
#   already_installed: true
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# Credentials (generated in main)
VALKEY_PASS=""

# ----
# Conflict Detection
# ----

#
# Check for Redis conflict before installation

check_redis_conflict() {
	# Check if Redis service is running
	if systemctl is-active --quiet redis 2> /dev/null || systemctl is-active --quiet redis-server 2> /dev/null; then
		echo "Error: Redis is already installed and running on this server" >&2
		echo "Both Valkey and Redis use port 6379 and cannot coexist." >&2
		echo "Please uninstall Redis first if you want to use Valkey." >&2
		exit 1
	fi

	# Check if Redis packages are installed (even if service is stopped)
	if dpkg -l redis-server 2> /dev/null | grep -q '^ii'; then
		echo "Error: Redis packages are installed on this server" >&2
		echo "Both Valkey and Redis use port 6379 and cannot coexist." >&2
		echo "Please remove Redis packages first: apt-get purge redis-server" >&2
		exit 1
	fi
}

# ----
# Repository Setup
# ----

#
# Add Valkey official repository if package not available

setup_valkey_repo() {
	# Check if valkey-server is already available
	if apt-cache show valkey-server > /dev/null 2>&1; then
		return 0
	fi

	echo "-> Adding Valkey official repository..."

	# Determine codename for the repository
	local codename
	codename=$(lsb_release -cs 2> /dev/null || echo "")

	if [[ -z $codename ]]; then
		echo "Error: Could not determine distribution codename" >&2
		exit 1
	fi

	# Create keyrings directory if it doesn't exist
	if [[ ! -d /etc/apt/keyrings ]]; then
		run_cmd mkdir -p /etc/apt/keyrings
	fi

	# Download and add Valkey GPG key
	if ! curl -fsSL https://packages.valkey.io/gpg | run_cmd gpg --batch --yes --dearmor -o /etc/apt/keyrings/valkey.gpg; then
		echo "Error: Failed to add Valkey GPG key" >&2
		exit 1
	fi

	# Add Valkey repository
	local repo_file="/etc/apt/sources.list.d/valkey.list"
	if ! echo "deb [signed-by=/etc/apt/keyrings/valkey.gpg] https://packages.valkey.io/deb ${codename} main" | run_cmd tee "$repo_file" > /dev/null; then
		echo "Error: Failed to add Valkey repository" >&2
		exit 1
	fi

	# Update package list
	if ! run_cmd apt-get update -q; then
		echo "Error: Failed to update package list after adding Valkey repository" >&2
		exit 1
	fi
}

# ----
# Installation Functions
# ----

#
# Package Installation
# ----

#
# Install Valkey packages

install_packages() {
	echo "-> Installing Valkey..."

	local packages=(valkey-server valkey-tools)

	if ! apt_get_with_retry install -y "${packages[@]}" 2>&1; then
		echo "Error: Failed to install Valkey packages" >&2
		exit 1
	fi

	# Enable and start Valkey service
	if ! systemctl is-enabled --quiet valkey-server 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet valkey-server; then
			echo "Error: Failed to enable Valkey service" >&2
			exit 1
		fi
	fi

	if ! systemctl is-active --quiet valkey-server 2> /dev/null; then
		if ! run_cmd systemctl start valkey-server; then
			echo "Error: Failed to start Valkey service" >&2
			exit 1
		fi
	fi

	# Verify Valkey is accepting connections
	echo "-> Waiting for Valkey to accept connections..."
	local max_wait=30
	local waited=0
	while ! run_cmd valkey-cli ping 2> /dev/null | grep -q PONG; do
		if ((waited >= max_wait)); then
			echo "Error: Valkey started but is not accepting connections" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

#
# Security Configuration
# ----

#
# Configure Valkey authentication

configure_authentication() {
	echo "-> Configuring Valkey authentication..."

	local config_file="/etc/valkey/valkey.conf"

	# Backup original config if not already backed up
	if [[ ! -f "${config_file}.orig" ]]; then
		run_cmd cp "$config_file" "${config_file}.orig"
	fi

	# Set requirepass (remove any existing requirepass lines first)
	run_cmd sed -i '/^requirepass /d' "$config_file"
	run_cmd sed -i '/^# requirepass /d' "$config_file"

	# Append requirepass at the end
	if ! echo "requirepass ${VALKEY_PASS}" | run_cmd tee -a "$config_file" > /dev/null; then
		echo "Error: Failed to set Valkey password" >&2
		exit 1
	fi

	# Restart Valkey to apply password
	if ! run_cmd systemctl restart valkey-server; then
		echo "Error: Failed to restart Valkey with new configuration" >&2
		exit 1
	fi

	# Verify Valkey is accepting connections with password
	echo "-> Verifying Valkey authentication..."
	local max_wait=10
	local waited=0
	while ! VALKEYCLI_AUTH="$VALKEY_PASS" run_cmd valkey-cli ping 2> /dev/null | grep -q PONG; do
		if ((waited >= max_wait)); then
			echo "Error: Valkey is not accepting authenticated connections" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

#
# Logging Configuration
# ----

#
# Configure logrotate for Valkey logs

config_logrotate() {
	echo "-> Setting up Valkey logrotate..."

	local logrotate_config="/etc/logrotate.d/valkey-deployer"

	if ! run_cmd tee "$logrotate_config" > /dev/null <<- 'EOF'; then
		/var/log/valkey/*.log {
		    daily
		    rotate 5
		    maxage 30
		    missingok
		    notifempty
		    compress
		    delaycompress
		    copytruncate
		}
	EOF
		echo "Error: Failed to create Valkey logrotate config" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	# Check for Redis conflict FIRST (before any installation)
	check_redis_conflict

	# Check if Valkey is already installed - exit gracefully if so
	if systemctl is-active --quiet valkey-server 2> /dev/null; then
		echo "-> Valkey server is already installed and running"

		# Return success with marker indicating already installed
		if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
			status: success
			already_installed: true
		EOF
			echo "Error: Failed to write output file" >&2
			exit 1
		fi
		exit 0
	fi

	# Setup repository if needed
	setup_valkey_repo

	# Generate credentials for fresh install
	VALKEY_PASS=$(openssl rand -base64 24)

	# Execute installation tasks
	install_packages
	configure_authentication
	config_logrotate

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		valkey_pass: ${VALKEY_PASS}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
