#!/usr/bin/env bash

#
# Redis Installation
#
# Installs Redis server and configures password authentication.
#
# Output (fresh install):
#   status: success
#   redis_pass: Ek3jF8mNpQ2rS5tV
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
REDIS_PASS=""

# ----
# Conflict Detection
# ----

#
# Check for Valkey conflict before installation

check_valkey_conflict() {
	# Check if Valkey service is running
	if systemctl is-active --quiet valkey 2> /dev/null || systemctl is-active --quiet valkey-server 2> /dev/null; then
		echo "Error: Valkey is already installed and running on this server" >&2
		echo "Both Redis and Valkey use port 6379 and cannot coexist." >&2
		echo "Please uninstall Valkey first if you want to use Redis." >&2
		exit 1
	fi

	# Check if Valkey packages are installed (even if service is stopped)
	if dpkg -l valkey-server 2> /dev/null | grep -q '^ii'; then
		echo "Error: Valkey packages are installed on this server" >&2
		echo "Both Redis and Valkey use port 6379 and cannot coexist." >&2
		echo "Please remove Valkey packages first: apt-get purge valkey-server" >&2
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
# Install Redis packages

install_packages() {
	echo "-> Installing Redis..."

	local packages=(redis-server redis-tools)

	if ! apt_get_with_retry install -y "${packages[@]}" 2>&1; then
		echo "Error: Failed to install Redis packages" >&2
		exit 1
	fi

	# Enable and start Redis service
	if ! systemctl is-enabled --quiet redis-server 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet redis-server; then
			echo "Error: Failed to enable Redis service" >&2
			exit 1
		fi
	fi

	if ! systemctl is-active --quiet redis-server 2> /dev/null; then
		if ! run_cmd systemctl start redis-server; then
			echo "Error: Failed to start Redis service" >&2
			exit 1
		fi
	fi

	# Verify Redis is accepting connections
	echo "-> Waiting for Redis to accept connections..."
	local max_wait=30
	local waited=0
	while ! run_cmd redis-cli ping 2> /dev/null | grep -q PONG; do
		if ((waited >= max_wait)); then
			echo "Error: Redis started but is not accepting connections" >&2
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
# Configure Redis authentication

configure_authentication() {
	echo "-> Configuring Redis authentication..."

	local config_file="/etc/redis/redis.conf"

	# Backup original config if not already backed up
	if [[ ! -f "${config_file}.orig" ]]; then
		run_cmd cp "$config_file" "${config_file}.orig"
	fi

	# Set requirepass (remove any existing requirepass lines first)
	run_cmd sed -i '/^requirepass /d' "$config_file"
	run_cmd sed -i '/^# requirepass /d' "$config_file"

	# Append requirepass at the end
	if ! echo "requirepass ${REDIS_PASS}" | run_cmd tee -a "$config_file" > /dev/null; then
		echo "Error: Failed to set Redis password" >&2
		exit 1
	fi

	# Restart Redis to apply password
	if ! run_cmd systemctl restart redis-server; then
		echo "Error: Failed to restart Redis with new configuration" >&2
		exit 1
	fi

	# Verify Redis is accepting connections with password
	echo "-> Verifying Redis authentication..."
	local max_wait=10
	local waited=0
	while ! REDISCLI_AUTH="$REDIS_PASS" run_cmd redis-cli ping 2> /dev/null | grep -q PONG; do
		if ((waited >= max_wait)); then
			echo "Error: Redis is not accepting authenticated connections" >&2
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
# Configure logrotate for Redis logs

config_logrotate() {
	echo "-> Setting up Redis logrotate..."

	local logrotate_config="/etc/logrotate.d/redis-deployer"

	if ! run_cmd tee "$logrotate_config" > /dev/null <<- 'EOF'; then
		/var/log/redis/*.log {
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
		echo "Error: Failed to create Redis logrotate config" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	# Check for Valkey conflict FIRST (before any installation)
	check_valkey_conflict

	# Check if Redis is already installed - exit gracefully if so
	if systemctl is-active --quiet redis-server 2> /dev/null; then
		echo "-> Redis server is already installed and running"

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

	# Generate credentials for fresh install
	REDIS_PASS=$(openssl rand -base64 24)

	# Execute installation tasks
	install_packages
	configure_authentication
	config_logrotate

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		redis_pass: ${REDIS_PASS}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
