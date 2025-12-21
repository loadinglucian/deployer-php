#!/usr/bin/env bash

#
# Memcached Installation
#
# Installs Memcached server and configures it for local-only access.
#
# Output (fresh install):
#   status: success
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

# ----
# Installation Functions
# ----

#
# Install Memcached packages

install_packages() {
	echo "→ Installing Memcached..."

	local packages=(memcached libmemcached-tools)

	if ! apt_get_with_retry install -y "${packages[@]}" 2>&1; then
		echo "Error: Failed to install Memcached packages" >&2
		exit 1
	fi

	# Enable Memcached service
	if ! systemctl is-enabled --quiet memcached 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet memcached; then
			echo "Error: Failed to enable Memcached service" >&2
			exit 1
		fi
	fi

	# Start Memcached service
	if ! systemctl is-active --quiet memcached 2> /dev/null; then
		if ! run_cmd systemctl start memcached; then
			echo "Error: Failed to start Memcached service" >&2
			exit 1
		fi
	fi

	# Verify Memcached is running
	echo "→ Verifying Memcached is running..."
	local max_wait=10
	local waited=0
	while ! systemctl is-active --quiet memcached 2> /dev/null; do
		if ((waited >= max_wait)); then
			echo "Error: Memcached service failed to start" >&2
			exit 1
		fi
		sleep 1
		waited=$((waited + 1))
	done
}

# ----
# Security Configuration
# ----

#
# Ensure Memcached listens on localhost only

secure_configuration() {
	local config_file="/etc/memcached.conf"

	# Check if already configured for localhost only
	if grep -q "^-l 127.0.0.1" "$config_file" 2> /dev/null; then
		return 0
	fi

	echo "→ Configuring Memcached for localhost-only access..."

	# Replace any existing -l directive or add if missing
	if grep -q "^-l" "$config_file" 2> /dev/null; then
		# Replace existing -l directive with localhost
		run_cmd sed -i 's/^-l.*/-l 127.0.0.1/' "$config_file"
	elif grep -q "^#.*-l" "$config_file" 2> /dev/null; then
		# Uncomment and set to localhost
		run_cmd sed -i 's/^#.*-l.*/-l 127.0.0.1/' "$config_file"
	else
		# Add the line if no -l directive exists
		echo "-l 127.0.0.1" | run_cmd tee -a "$config_file" > /dev/null
	fi

	# Restart to apply changes
	if ! run_cmd systemctl restart memcached; then
		echo "Error: Failed to restart Memcached after configuration" >&2
		exit 1
	fi
}

# ----
# Logging Configuration
# ----

#
# Configure logrotate for Memcached logs

config_logrotate() {
	echo "→ Setting up Memcached logrotate..."

	local logrotate_config="/etc/logrotate.d/memcached-deployer"

	if ! run_cmd tee "$logrotate_config" > /dev/null <<- 'EOF'; then
		/var/log/memcached.log {
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
		echo "Error: Failed to create Memcached logrotate config" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	# Check if Memcached is already installed - exit gracefully if so
	if systemctl is-active --quiet memcached 2> /dev/null; then
		echo "→ Memcached server is already installed and running"

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

	# Execute installation tasks
	install_packages
	secure_configuration
	config_logrotate

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
