#!/usr/bin/env bash

#
# Demo Site Setup Playbook
#
# Create deployer user, configure permissions, setup demo site
# ----
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_FAMILY      - Distribution family: debian|fedora|redhat|amazon
#   DEPLOYER_PERMS       - Permissions: root|sudo
#
# Returns YAML with:
#   - status: success
#   - demo_site_path: /home/deployer/demo/public
#   - deployer_user: created
#   - caddy_configured: true
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_FAMILY ]] && echo "Error: DEPLOYER_FAMILY required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

#
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

#
# Get PHP-FPM user dynamically

get_php_fpm_user() {
	if [[ $DEPLOYER_FAMILY == 'debian' ]]; then
		echo 'www-data'
	else
		local config_file='/etc/php-fpm.d/www.conf'
		if [[ -f $config_file ]]; then
			local user
			user=$(grep -E '^\s*user\s*=' "$config_file" | awk '{print $3}' | tr -d ';')
			if [[ -n $user ]]; then
				echo "$user"
			else
				echo 'apache'
			fi
		else
			echo 'apache'
		fi
	fi
}

#
# Get PHP-FPM service name

get_php_fpm_service() {
	if [[ $DEPLOYER_FAMILY == 'debian' ]]; then
		echo 'php8.4-fpm'
	else
		echo 'php-fpm'
	fi
}

#
# Setup Functions

create_deployer_user() {
	if id -u deployer > /dev/null 2>&1; then
		echo "✓ Deployer user already exists"
	else
		echo "✓ Creating deployer user..."
		if ! run_cmd useradd -m -s /bin/bash deployer; then
			echo "Error: Failed to create deployer user" >&2
			exit 1
		fi
	fi

	# Add caddy user to deployer group so it can access deployer's files
	if ! id -nG caddy 2> /dev/null | grep -qw deployer; then
		echo "✓ Adding caddy user to deployer group..."
		if ! run_cmd usermod -aG deployer caddy; then
			echo "Error: Failed to add caddy to deployer group" >&2
			exit 1
		fi

		# Restart Caddy so it picks up the new group membership
		if systemctl is-active --quiet caddy 2> /dev/null; then
			echo "✓ Restarting Caddy to apply group membership..."
			if ! run_cmd systemctl restart caddy; then
				echo "Error: Failed to restart Caddy" >&2
				exit 1
			fi
		fi
	fi

	# Add PHP-FPM user to deployer group so it can access files
	local php_fpm_user php_fpm_service
	php_fpm_user=$(get_php_fpm_user)
	php_fpm_service=$(get_php_fpm_service)

	if id -u "$php_fpm_user" > /dev/null 2>&1; then
		if ! id -nG "$php_fpm_user" 2> /dev/null | grep -qw deployer; then
			echo "✓ Adding $php_fpm_user user to deployer group..."
			if ! run_cmd usermod -aG deployer "$php_fpm_user"; then
				echo "Error: Failed to add $php_fpm_user to deployer group" >&2
				exit 1
			fi

			# Restart PHP-FPM so it picks up the new group membership
			if systemctl is-active --quiet "$php_fpm_service" 2> /dev/null; then
				echo "✓ Restarting PHP-FPM to apply group membership..."
				if ! run_cmd systemctl restart "$php_fpm_service"; then
					echo "Error: Failed to restart PHP-FPM" >&2
					exit 1
				fi
			fi
		fi
	else
		echo "Warning: PHP-FPM user '$php_fpm_user' not found, skipping group assignment"
	fi
}

setup_demo_site() {
	echo "✓ Setting up demo site..."

	# Create directory structure
	if [[ ! -d /home/deployer/demo/public ]]; then
		if ! run_cmd mkdir -p /home/deployer/demo/public; then
			echo "Error: Failed to create demo site directory" >&2
			exit 1
		fi
	fi

	# Create index.php
	if [[ ! -f /home/deployer/demo/public/index.php ]]; then
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

configure_caddy() {
	echo "✓ Configuring Caddy..."

	# Determine PHP-FPM socket path
	local php_fpm_socket
	if [[ $DEPLOYER_FAMILY == 'debian' ]]; then
		php_fpm_socket='/run/php/php8.4-fpm.sock'
	else
		php_fpm_socket='/run/php-fpm/www.sock'
	fi

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

	# Create Caddyfile with logging - using the simplest PHP configuration
	if ! run_cmd tee /etc/caddy/Caddyfile > /dev/null <<- EOF; then
		{
			log {
				output file /var/log/caddy/access.log
				format json
			}
		}

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
		echo "Error: Failed to create Caddyfile" >&2
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

#
# Main Execution
# ----

main() {
	# Execute setup tasks
	create_deployer_user
	setup_demo_site
	configure_caddy

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		demo_site_path: /home/deployer/demo/public
		deployer_user: created
		caddy_configured: true
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
