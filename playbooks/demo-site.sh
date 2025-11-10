#!/usr/bin/env bash

#
# Demo Site Setup Playbook - Ubuntu/Debian Only
#
# Provision demo site (requires deployer user and Caddy structure pre-configured)
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Requires server-install to have run first (creates /etc/caddy/conf.d/sites/ directory).
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_PERMS       - Permissions: root|sudo
#
# Returns YAML with:
#   - status: success
#   - demo_site_path: /home/deployer/demo/public
#   - deployer_user: existing
#   - demo_site_configured: true
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

# ----
# Helpers
# ----

#
# Execute command with appropriate permissions

run_cmd() {
	if [[ $DEPLOYER_PERMS == 'root' ]]; then
		"$@"
	else
		sudo -n "$@"
	fi
}

# ----
# Setup Functions
# ----

#
# Prerequisites
# ----

#
# Verify deployer user and home directory exist

require_deployer_user() {
	if ! id -u deployer > /dev/null 2>&1; then
		echo "Error: Deployer user not found. Run server:install before demo-site." >&2
		exit 1
	fi

	if ! run_cmd test -d /home/deployer; then
		echo "Error: Deployer home directory missing. Run server:install before demo-site." >&2
		exit 1
	fi
}

#
# Demo Site Setup
# ----

#
# Create demo site directory structure and files

setup_demo_site() {
	echo "✓ Setting up demo site..."

	# Create directory structure
	if ! run_cmd test -d /home/deployer/demo/public; then
		if ! run_cmd mkdir -p /home/deployer/demo/public; then
			echo "Error: Failed to create demo site directory" >&2
			exit 1
		fi
	fi

	# Create index.php
	if ! run_cmd test -f /home/deployer/demo/public/index.php; then
		if ! run_cmd tee /home/deployer/demo/public/index.php > /dev/null <<- 'EOF'; then
			<?php echo 'hello, world';
		EOF
			echo "Error: Failed to create index.php" >&2
			exit 1
		fi
	fi

	# Set ownership and group permissions
	# Caddy user is in deployer group, so set group-readable permissions
	if ! run_cmd chown -R deployer:deployer /home/deployer/demo; then
		echo "Error: Failed to set ownership on demo site" >&2
		exit 1
	fi

	# Set permissions: owner+group can read/execute dirs, read files (750 dirs, 640 files)
	if ! run_cmd chmod 750 /home/deployer; then
		echo "Error: Failed to set permissions on deployer home" >&2
		exit 1
	fi

	if ! run_cmd chmod 750 /home/deployer/demo; then
		echo "Error: Failed to set permissions on demo directory" >&2
		exit 1
	fi

	if ! run_cmd chmod 750 /home/deployer/demo/public; then
		echo "Error: Failed to set permissions on public directory" >&2
		exit 1
	fi

	if ! run_cmd chmod 640 /home/deployer/demo/public/index.php; then
		echo "Error: Failed to set permissions on index.php" >&2
		exit 1
	fi
}

#
# Caddy Configuration
# ----

#
# Configure Caddy for demo site

configure_demo_site() {
	echo "✓ Configuring demo site..."

	# PHP-FPM socket path (debian family)
	local php_fpm_socket='/run/php/php8.4-fpm.sock'

	# Create log directory
	if [[ ! -d /var/log/caddy ]]; then
		if ! run_cmd mkdir -p /var/log/caddy; then
			echo "Error: Failed to create Caddy log directory" >&2
			exit 1
		fi
	fi

	if ! run_cmd chown -R caddy:caddy /var/log/caddy; then
		echo "Error: Failed to set ownership on Caddy log directory" >&2
		exit 1
	fi

	# Create demo.caddy - demo site configuration
	if ! run_cmd tee /etc/caddy/conf.d/sites/demo.caddy > /dev/null <<- EOF; then
		# Demo site - HTTP only (can't get HTTPS cert for IP address)
		http://:80 {
			root * /home/deployer/demo/public
			encode gzip

			log {
				output file /var/log/caddy/demo-access.log {
					roll_size 100mb
					roll_keep 5
					roll_keep_for 720h
				}
				format json
			}

			# This single line handles everything: PHP files, index.php routing, and static files
			php_fastcgi unix/${php_fpm_socket}
		}
	EOF
		echo "Error: Failed to create demo.caddy" >&2
		exit 1
	fi

	# Enable and start Caddy
	if ! systemctl is-enabled --quiet caddy 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet caddy; then
			echo "Error: Failed to enable Caddy service" >&2
			exit 1
		fi
	fi

	if systemctl is-active --quiet caddy 2> /dev/null; then
		if ! run_cmd systemctl reload caddy; then
			echo "Error: Failed to reload Caddy service" >&2
			exit 1
		fi
	else
		if ! run_cmd systemctl start caddy; then
			echo "Error: Failed to start Caddy service" >&2
			exit 1
		fi
	fi
}

# ----
# Main Execution
# ----

main() {
	# Execute setup tasks
	require_deployer_user
	setup_demo_site
	configure_demo_site

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		demo_site_path: /home/deployer/demo/public
		deployer_user: existing
		demo_site_configured: true
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
