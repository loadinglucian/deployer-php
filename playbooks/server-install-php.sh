#!/usr/bin/env bash

#
# PHP Installation Playbook - Ubuntu/Debian Only
#
# Install specified PHP version with FPM and common extensions
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Both distributions use apt package manager and follow debian conventions.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE     - Output file path
#   DEPLOYER_DISTRO          - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS           - Permissions: root|sudo
#   DEPLOYER_PHP_VERSION     - PHP version to install (e.g., 8.4, 8.3, 7.4)
#   DEPLOYER_PHP_SET_DEFAULT - Set as system default: true|false
#
# Returns YAML with:
#   - status: success
#   - php_version: installed PHP version
#   - is_default: whether this version is set as system default
#   - fpm_socket_path: path to PHP-FPM socket
#   - tasks_completed: list of completed tasks
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_PHP_VERSION ]] && echo "Error: DEPLOYER_PHP_VERSION required" && exit 1
[[ -z $DEPLOYER_PHP_SET_DEFAULT ]] && echo "Error: DEPLOYER_PHP_SET_DEFAULT required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Installation Functions
# ----

#
# Repository Setup
# ----

#
# Setup PHP repository

setup_php_repository() {
	echo "✓ Setting up PHP repository..."

	case $DEPLOYER_DISTRO in
		ubuntu)
			# PHP PPA (Ubuntu only)
			if ! grep -qr "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2> /dev/null; then
				if ! run_cmd env DEBIAN_FRONTEND=noninteractive add-apt-repository -y ppa:ondrej/php 2>&1; then
					echo "Error: Failed to add PHP PPA" >&2
					exit 1
				fi
			fi
			;;
		debian)
			# Sury PHP repository (Debian only)
			if ! [[ -f /usr/share/keyrings/php-sury-archive-keyring.gpg ]]; then
				if ! curl -fsSL 'https://packages.sury.org/php/apt.gpg' | run_cmd gpg --batch --yes --dearmor -o /usr/share/keyrings/php-sury-archive-keyring.gpg; then
					echo "Error: Failed to add Sury PHP GPG key" >&2
					exit 1
				fi
			fi

			if ! [[ -f /etc/apt/sources.list.d/php-sury.list ]]; then
				local debian_codename
				debian_codename=$(lsb_release -sc)
				if ! echo "deb [signed-by=/usr/share/keyrings/php-sury-archive-keyring.gpg] https://packages.sury.org/php/ ${debian_codename} main" | run_cmd tee /etc/apt/sources.list.d/php-sury.list > /dev/null; then
					echo "Error: Failed to add Sury PHP repository" >&2
					exit 1
				fi
			fi
			;;
	esac
}

#
# Package Installation
# ----

#
# Install PHP packages for specified version

install_php_packages() {
	echo "✓ Installing PHP ${DEPLOYER_PHP_VERSION}..."

	# Update package lists
	echo "✓ Updating package lists..."
	if ! apt_get_with_retry update -q; then
		echo "Error: Failed to update package lists" >&2
		exit 1
	fi

	# Install PHP packages
	if ! apt_get_with_retry install -y -q --no-install-recommends \
		php${DEPLOYER_PHP_VERSION}-cli \
		php${DEPLOYER_PHP_VERSION}-fpm \
		php${DEPLOYER_PHP_VERSION}-common \
		php${DEPLOYER_PHP_VERSION}-opcache \
		php${DEPLOYER_PHP_VERSION}-bcmath \
		php${DEPLOYER_PHP_VERSION}-curl \
		php${DEPLOYER_PHP_VERSION}-mbstring \
		php${DEPLOYER_PHP_VERSION}-xml \
		php${DEPLOYER_PHP_VERSION}-zip \
		php${DEPLOYER_PHP_VERSION}-gd \
		php${DEPLOYER_PHP_VERSION}-intl \
		php${DEPLOYER_PHP_VERSION}-soap 2>&1; then
		echo "Error: Failed to install PHP ${DEPLOYER_PHP_VERSION} packages" >&2
		exit 1
	fi
}

#
# PHP-FPM Configuration
# ----

#
# Configure PHP-FPM for the installed version

configure_php_fpm() {
	echo "✓ Configuring PHP-FPM..."

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

	# Enable and start PHP-FPM service
	if ! systemctl is-enabled --quiet php${DEPLOYER_PHP_VERSION}-fpm 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet php${DEPLOYER_PHP_VERSION}-fpm; then
			echo "Error: Failed to enable PHP-FPM service" >&2
			exit 1
		fi
	fi
	if ! systemctl is-active --quiet php${DEPLOYER_PHP_VERSION}-fpm 2> /dev/null; then
		if ! run_cmd systemctl start php${DEPLOYER_PHP_VERSION}-fpm; then
			echo "Error: Failed to start PHP-FPM service" >&2
			exit 1
		fi
	fi
}

#
# User Group Configuration
# ----

#
# Configure PHP-FPM user group membership for file access

configure_php_user_groups() {
	# Add www-data (PHP-FPM user) to deployer group so it can access files
	if id -u www-data > /dev/null 2>&1; then
		if ! id -nG www-data 2> /dev/null | grep -qw deployer; then
			echo "✓ Adding www-data user to deployer group..."
			if ! run_cmd usermod -aG deployer www-data; then
				echo "Error: Failed to add www-data to deployer group" >&2
				exit 1
			fi

			# Restart PHP-FPM so it picks up the new group membership
			if systemctl is-active --quiet php${DEPLOYER_PHP_VERSION}-fpm 2> /dev/null; then
				echo "✓ Restarting PHP-FPM to apply group membership..."
				if ! run_cmd systemctl restart php${DEPLOYER_PHP_VERSION}-fpm; then
					echo "Error: Failed to restart PHP-FPM" >&2
					exit 1
				fi
			fi
		fi
	else
		echo "Warning: PHP-FPM user 'www-data' not found, skipping group assignment"
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

	echo "✓ Setting PHP ${DEPLOYER_PHP_VERSION} as system default..."

	# Set alternatives for php binaries
	if command -v update-alternatives > /dev/null 2>&1; then
		if run_cmd update-alternatives --set php /usr/bin/php${DEPLOYER_PHP_VERSION} 2> /dev/null; then
			echo "✓ Set php alternative"
		fi

		if run_cmd update-alternatives --set php-config /usr/bin/php-config${DEPLOYER_PHP_VERSION} 2> /dev/null; then
			echo "✓ Set php-config alternative"
		fi

		if run_cmd update-alternatives --set phpize /usr/bin/phpize${DEPLOYER_PHP_VERSION} 2> /dev/null; then
			echo "✓ Set phpize alternative"
		fi
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

	echo "✓ Updating Caddy localhost configuration..."

	# Check if this PHP version's endpoint already exists
	if grep -q "handle_path /php${DEPLOYER_PHP_VERSION}/" /etc/caddy/conf.d/localhost.caddy 2> /dev/null; then
		echo "✓ PHP ${DEPLOYER_PHP_VERSION} endpoint already configured"
		return 0
	fi

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
	local fpm_socket_path="/run/php/php${DEPLOYER_PHP_VERSION}-fpm.sock"
	local is_default="false"

	# Execute installation tasks
	setup_php_repository
	install_php_packages
	configure_php_fpm
	configure_php_user_groups
	set_as_default
	update_caddy_config

	if [[ $DEPLOYER_PHP_SET_DEFAULT == 'true' ]]; then
		is_default="true"
	fi

	# Get actual PHP version
	local php_version
	php_version=$(php${DEPLOYER_PHP_VERSION} -r "echo PHP_VERSION;" 2> /dev/null || echo "$DEPLOYER_PHP_VERSION")

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		php_version: $php_version
		is_default: $is_default
		fpm_socket_path: $fpm_socket_path
		tasks_completed:
		  - setup_php_repository
		  - install_php_packages
		  - configure_php_fpm
		  - configure_php_user_groups
		  - set_as_default
		  - update_caddy_config
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
