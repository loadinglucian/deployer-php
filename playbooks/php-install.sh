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
	echo "→ Configuring PHP-FPM for PHP ${DEPLOYER_PHP_VERSION}..."

	local pool_config="/etc/php/${DEPLOYER_PHP_VERSION}/fpm/pool.d/www.conf"

	# Set socket ownership for Nginx (www-data user)
	if ! run_cmd sed -i 's/^;*listen\.owner = .*/listen.owner = www-data/' "$pool_config"; then
		echo "Error: Failed to set PHP-FPM socket owner" >&2
		exit 1
	fi
	if ! run_cmd sed -i 's/^;*listen\.group = .*/listen.group = www-data/' "$pool_config"; then
		echo "Error: Failed to set PHP-FPM socket group" >&2
		exit 1
	fi
	if ! run_cmd sed -i 's/^;*listen\.mode = .*/listen.mode = 0660/' "$pool_config"; then
		echo "Error: Failed to set PHP-FPM socket mode" >&2
		exit 1
	fi

	# Enable PHP-FPM status page
	if ! run_cmd sed -i 's/^;*pm\.status_path = .*/pm.status_path = \/fpm-status/' "$pool_config"; then
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
# Nginx Configuration
# ----

#
# Add PHP-FPM status endpoint to Nginx stub_status config

update_nginx_config() {
	local stub_status="/etc/nginx/sites-available/stub_status"

	if ! [[ -f "$stub_status" ]]; then
		echo "Warning: stub_status config not found, skipping Nginx configuration"
		return 0
	fi

	# Check if this PHP version's endpoint already exists
	if grep -q "location /php${DEPLOYER_PHP_VERSION}/" "$stub_status" 2> /dev/null; then
		echo "→ PHP ${DEPLOYER_PHP_VERSION} status endpoint already configured"
		return 0
	fi

	echo "→ Adding PHP ${DEPLOYER_PHP_VERSION} status endpoint to Nginx..."

	# Check if the marker exists
	if ! grep -q "#### DEPLOYER-PHP CONFIG ####" "$stub_status" 2> /dev/null; then
		echo "Error: stub_status marker not found. File may have been modified manually." >&2
		exit 1
	fi

	# Create the new location block
	local php_block="
        # PHP ${DEPLOYER_PHP_VERSION} FPM Status
        location /php${DEPLOYER_PHP_VERSION}/fpm-status {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME /fpm-status;
            fastcgi_pass unix:/run/php/php${DEPLOYER_PHP_VERSION}-fpm.sock;
            access_log off;
            allow 127.0.0.1;
            deny all;
        }
"

	# Insert before the marker using awk
	local temp_config
	temp_config=$(mktemp)

	if ! awk -v block="$php_block" '
		/#### DEPLOYER-PHP CONFIG ####/ {
			print block
		}
		{ print }
	' "$stub_status" > "$temp_config"; then
		rm -f "$temp_config"
		echo "Error: Failed to generate Nginx configuration" >&2
		exit 1
	fi

	if ! run_cmd cp "$temp_config" "$stub_status"; then
		rm -f "$temp_config"
		echo "Error: Failed to write Nginx configuration" >&2
		exit 1
	fi

	rm -f "$temp_config"

	# Test and reload Nginx
	if ! run_cmd nginx -t 2>&1; then
		echo "Error: Nginx configuration test failed" >&2
		exit 1
	fi

	if systemctl is-active --quiet nginx 2> /dev/null; then
		if ! run_cmd systemctl reload nginx 2> /dev/null; then
			echo "Warning: Failed to reload Nginx configuration"
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
	update_nginx_config

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
