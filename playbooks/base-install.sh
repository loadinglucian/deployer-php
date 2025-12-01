#!/usr/bin/env bash

#
# Server Installation Playbook - Ubuntu/Debian Only
#
# Install Caddy, Git, and configure base server
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Both distributions use apt package manager and follow debian conventions.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_DISTRO      - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS       - Permissions: root|sudo
#
# Returns YAML with:
#   - status: success
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
# Install packages
# ----

install_packages() {
	echo "→ Installing packages..."

	local common_packages=(curl zip unzip caddy git rsync)
	local distro_packages

	case $DEPLOYER_DISTRO in
		ubuntu)
			distro_packages=(software-properties-common)
			if ! apt_get_with_retry install -y "${common_packages[@]}" "${distro_packages[@]}"; then
				echo "Error: Failed to install packages" >&2
				exit 1
			fi
			;;
		debian)
			distro_packages=(apt-transport-https lsb-release ca-certificates)
			if ! apt_get_with_retry install -y "${common_packages[@]}" "${distro_packages[@]}"; then
				echo "Error: Failed to install packages" >&2
				exit 1
			fi
			;;
	esac
}

#
# Base Caddy config
# ----

config_caddy() {
	echo "→ Setting up Caddy base config..."

	#
	# Create directory structure

	if ! run_cmd mkdir -p /etc/caddy/conf.d/sites; then
		echo "Error: Failed to create Caddy config directories" >&2
		exit 1
	fi

	#
	# Create main Caddyfile with global settings and imports

	# Check if our custom config is already in place
	if ! grep -q "import conf.d/localhost.caddy" /etc/caddy/Caddyfile 2> /dev/null; then
		echo "→ Creating Caddyfile with custom configuration..."
		if ! run_cmd tee /etc/caddy/Caddyfile > /dev/null <<- 'EOF'; then
			{
				metrics

				log {
					output file /var/log/caddy/access.log
					format json
				}
			}

			# Import localhost-only endpoints (monitoring, status pages)
			import conf.d/localhost.caddy

			# Import all site configurations
			import conf.d/sites/*.caddy
		EOF
			echo "Error: Failed to create main Caddyfile" >&2
			exit 1
		fi
	fi

	# Create localhost.caddy - monitoring endpoints only accessible via localhost
	# (PHP-FPM status endpoint will be added by PHP installation playbook)
	if ! run_cmd test -f /etc/caddy/conf.d/localhost.caddy; then
		if ! run_cmd tee /etc/caddy/conf.d/localhost.caddy > /dev/null <<- 'EOF'; then
			# PHP-FPM status endpoints - localhost only (not accessible from internet)
			http://localhost:9001 {
				#### DEPLOYER-PHP CONFIG, WARRANTY VOID IF REMOVED :) ####
			}
		EOF
			echo "Error: Failed to create localhost.caddy" >&2
			exit 1
		fi
	fi

	# Reload Caddy to apply configuration changes
	if systemctl is-active --quiet caddy 2> /dev/null; then
		echo "→ Reloading Caddy configuration..."
		if ! run_cmd systemctl reload caddy 2> /dev/null; then
			echo "Warning: Failed to reload Caddy configuration"
		fi
	fi
}

# ----
# Main Execution
# ----

main() {
	# Execute installation tasks
	install_packages
	config_caddy

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
