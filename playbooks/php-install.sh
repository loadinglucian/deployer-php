#!/usr/bin/env bash

#
# PHP Installation
#
# Installs specified PHP version with selected extensions and configures PHP-FPM.
#
# Output:
#   status: success
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_PHP_VERSION ]] && echo "Error: DEPLOYER_PHP_VERSION required" && exit 1
[[ -z $DEPLOYER_PHP_SET_DEFAULT ]] && echo "Error: DEPLOYER_PHP_SET_DEFAULT required" && exit 1
[[ -z $DEPLOYER_PHP_EXTENSIONS ]] && echo "Error: DEPLOYER_PHP_EXTENSIONS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Installation Functions
# ----

#
# Package Installation
# ----

#
# Install PHP packages for specified version

install_php_packages() {
	echo "→ Installing PHP ${DEPLOYER_PHP_VERSION}..."

	# Parse comma-separated extensions
	IFS=',' read -ra extensions <<< "$DEPLOYER_PHP_EXTENSIONS"
	local packages=()

	for ext in "${extensions[@]}"; do
		ext=$(echo "$ext" | xargs) # trim whitespace
		packages+=("php${DEPLOYER_PHP_VERSION}-${ext}")
	done

	# Install selected packages
	if ! apt_get_with_retry install -y "${packages[@]}" 2>&1; then
		echo "Error: Failed to install PHP ${DEPLOYER_PHP_VERSION} packages" >&2
		exit 1
	fi
}

#
# Composer Installation
# ----

#
# Install Composer if not present

install_composer() {
	# Ensure composer is installed if not already present
	if ! command -v composer > /dev/null 2>&1; then
		echo "→ Installing Composer..."
		if ! run_cmd curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php; then
			echo "Error: Failed to download Composer installer" >&2
			exit 1
		fi

		if ! run_cmd php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer; then
			echo "Error: Failed to install Composer" >&2
			run_cmd rm -f /tmp/composer-setup.php
			exit 1
		fi

		run_cmd rm /tmp/composer-setup.php

		if ! command -v composer > /dev/null 2>&1; then
			echo "Error: Composer installation failed (command not found)" >&2
			exit 1
		fi
	fi
}

#
# PHP-FPM Configuration
# ----

#
# Configure PHP-FPM for the installed version

configure_php_fpm() {
	echo "→ Configuring PHP-FPM..."

	local pool_config="/etc/php/${DEPLOYER_PHP_VERSION}/fpm/pool.d/www.conf"

	# Set socket ownership so Caddy can access it
	if ! run_cmd sed -i 's/^;listen.owner = .*/listen.owner = caddy/' "$pool_config"; then
		echo "Error: Failed to set PHP-FPM socket owner" >&2
		exit 1
	fi
	if ! run_cmd sed -i 's/^;listen.group = .*/listen.group = caddy/' "$pool_config"; then
		echo "Error: Failed to set PHP-FPM socket group" >&2
		exit 1
	fi
	if ! run_cmd sed -i 's/^;listen.mode = .*/listen.mode = 0660/' "$pool_config"; then
		echo "Error: Failed to set PHP-FPM socket mode" >&2
		exit 1
	fi

	# Enable PHP-FPM status page
	if ! run_cmd sed -i 's/^;pm.status_path = .*/pm.status_path = \/fpm-status/' "$pool_config"; then
		echo "Error: Failed to enable PHP-FPM status page" >&2
		exit 1
	fi

	# Enable PHP-FPM service
	if ! systemctl is-enabled --quiet php${DEPLOYER_PHP_VERSION}-fpm 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet php${DEPLOYER_PHP_VERSION}-fpm; then
			echo "Error: Failed to enable PHP-FPM service" >&2
			exit 1
		fi
	fi

	# Restart PHP-FPM to apply configuration changes (idempotent - starts if not running)
	if ! run_cmd systemctl restart php${DEPLOYER_PHP_VERSION}-fpm; then
		echo "Error: Failed to restart PHP-FPM service" >&2
		exit 1
	fi
}

#
# PHP-FPM logrotate config
# ----

config_logrotate() {
	echo "→ Setting up PHP-FPM logrotate..."

	local logrotate_config="/etc/logrotate.d/php-fpm-deployer"

	if ! run_cmd tee "$logrotate_config" > /dev/null <<- 'EOF'; then
		/var/log/php*-fpm.log {
		    daily
		    rotate 5
		    maxage 30
		    missingok
		    notifempty
		    compress
		    delaycompress
		    sharedscripts
		    postrotate
		        /bin/kill -SIGUSR1 $(cat /run/php/php*-fpm.pid 2>/dev/null) 2>/dev/null || true
		    endscript
		}
	EOF
		echo "Error: Failed to create PHP-FPM logrotate config" >&2
		exit 1
	fi
}

#
# Default Version Configuration
# ----

#
# Set PHP version as system default

set_as_default() {
	if [[ $DEPLOYER_PHP_SET_DEFAULT != 'true' ]]; then
		return 0
	fi

	echo "→ Setting PHP ${DEPLOYER_PHP_VERSION} as system default..."

	# Set alternatives for php binaries
	if command -v update-alternatives > /dev/null 2>&1; then
		run_cmd update-alternatives --set php /usr/bin/php${DEPLOYER_PHP_VERSION} 2> /dev/null
		run_cmd update-alternatives --set php-config /usr/bin/php-config${DEPLOYER_PHP_VERSION} 2> /dev/null
		run_cmd update-alternatives --set phpize /usr/bin/phpize${DEPLOYER_PHP_VERSION} 2> /dev/null
	fi
}

#
# Caddy Configuration
# ----

#
# Update Caddy localhost configuration with PHP-FPM endpoint

update_caddy_config() {
	if ! [[ -f /etc/caddy/conf.d/localhost.caddy ]]; then
		echo "Warning: localhost.caddy not found, skipping Caddy configuration"
		return 0
	fi

	# Check if this PHP version's endpoint already exists
	if grep -q "handle_path /php${DEPLOYER_PHP_VERSION}/" /etc/caddy/conf.d/localhost.caddy 2> /dev/null; then
		return 0
	fi

	echo "→ Updating Caddy localhost configuration..."

	# Check if the marker exists (file should be created by server-install.sh)
	if ! grep -q "#### DEPLOYER-PHP CONFIG, WARRANTY VOID IF REMOVED :) ####" /etc/caddy/conf.d/localhost.caddy 2> /dev/null; then
		echo "Error: localhost.caddy marker not found. File may have been modified manually." >&2
		exit 1
	fi

	# Create temporary file with the new handle block
	local temp_handle
	temp_handle=$(mktemp)

	cat > "$temp_handle" <<- EOF

		handle_path /php${DEPLOYER_PHP_VERSION}/* {
			reverse_proxy unix//run/php/php${DEPLOYER_PHP_VERSION}-fpm.sock {
				transport fastcgi {
					env SCRIPT_FILENAME /fpm-status
					env SCRIPT_NAME /fpm-status
				}
			}
		}
	EOF

	# Insert the handle block after the marker
	local temp_config
	temp_config=$(mktemp)

	if ! awk -v handle="$(cat "$temp_handle")" '
		/#### DEPLOYER-PHP CONFIG, WARRANTY VOID IF REMOVED :\) ####/ {
			print
			print handle
			next
		}
		{ print }
	' /etc/caddy/conf.d/localhost.caddy > "$temp_config"; then
		rm -f "$temp_handle" "$temp_config"
		echo "Error: Failed to update Caddy configuration" >&2
		exit 1
	fi

	# Replace the original file
	if ! run_cmd cp "$temp_config" /etc/caddy/conf.d/localhost.caddy; then
		rm -f "$temp_handle" "$temp_config"
		echo "Error: Failed to write Caddy configuration" >&2
		exit 1
	fi

	rm -f "$temp_handle" "$temp_config"

	# Reload Caddy to apply changes
	if systemctl is-active --quiet caddy 2> /dev/null; then
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
	install_php_packages
	configure_php_fpm
	config_logrotate
	set_as_default
	install_composer
	update_caddy_config

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
