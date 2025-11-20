#!/usr/bin/env bash

#
# Site Add Playbook - Ubuntu/Debian Only
#
# Provision new site with Capistrano-style directory structure
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Requires server-install to have run first (creates deployer user, Caddy, PHP).
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE  - Output file path
#   DEPLOYER_DISTRO       - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS        - Permissions: root|sudo
#   DEPLOYER_SITE_DOMAIN  - Site domain name
#   DEPLOYER_PHP_VERSION  - PHP version to use for this site
#
# Returns YAML with:
#   - status: success
#   - site_path: /home/deployer/sites/{domain}
#   - site_configured: true
#   - php_version: {version}
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
[[ -z $DEPLOYER_PHP_VERSION ]] && echo "Error: DEPLOYER_PHP_VERSION required" && exit 1
[[ -z $DEPLOYER_WWW_MODE ]] && echo "Error: DEPLOYER_WWW_MODE required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Setup Functions
# ----

#
# Directory Structure Setup
# ----

#
# Create Capistrano-style directory structure for site

setup_site_directories() {
	local domain=$1
	local site_path="/home/deployer/sites/${domain}"

	echo "→ Creating directory structure..."

	# Create main site directory
	if ! run_cmd test -d "$site_path"; then
		if ! run_cmd mkdir -p "$site_path"; then
			echo "Error: Failed to create directory: ${site_path}" >&2
			exit 1
		fi
	fi

	# Create Capistrano structure
	local dirs=(
		"${site_path}/releases"
		"${site_path}/shared"
		"${site_path}/repo"
		"${site_path}/current/public"
	)

	for dir in "${dirs[@]}"; do
		if ! run_cmd test -d "$dir"; then
			if ! run_cmd mkdir -p "$dir"; then
				echo "Error: Failed to create directory: ${dir}" >&2
				exit 1
			fi
		fi
	done

	# Set ownership
	if ! run_cmd chown -R deployer:deployer "$site_path"; then
		echo "Error: Failed to set ownership on site directory" >&2
		exit 1
	fi

	# Set permissions on all directories (750 - owner+group read/execute)
	if ! run_cmd find "$site_path" -type d -exec chmod 750 {} +; then
		echo "Error: Failed to set directory permissions" >&2
		exit 1
	fi
}

#
# Demo Site Setup
# ----

#
# Create demo hello-world page for site

setup_demo_page() {
	local domain=$1
	local index_file="/home/deployer/sites/${domain}/current/public/index.php"

	echo "→ Creating default page..."

	if ! run_cmd test -f "$index_file"; then
		if ! run_cmd tee "$index_file" > /dev/null <<- 'EOF'; then
			<?php
			echo '<ul>';
			echo '<li>Run <strong>site:https</strong> to enable HTTPS</li>';
			echo '<li>Deploy your application with <strong>site:deploy</strong></li>';
			echo '</ul>';
		EOF
			echo "Error: Failed to create index.php" >&2
			exit 1
		fi
	fi

	# Set file permissions (640 - owner+group read)
	if ! run_cmd chmod 640 "$index_file"; then
		echo "Error: Failed to set permissions on index.php" >&2
		exit 1
	fi

	# Set ownership
	if ! run_cmd chown deployer:deployer "$index_file"; then
		echo "Error: Failed to set ownership on index.php" >&2
		exit 1
	fi
}

#
# Caddy Configuration
# ----

#
# Configure Caddy vhost for site

configure_caddy_vhost() {
	local domain=$1
	local site_path="/home/deployer/sites/${domain}"
	local www_mode=$DEPLOYER_WWW_MODE

	echo "→ Creating Caddy configuration..."

	# Use specified PHP version
	local php_version=$DEPLOYER_PHP_VERSION

	echo "→ Using PHP ${php_version}"

	# PHP-FPM socket path (debian family)
	local php_fpm_socket="/run/php/php${php_version}-fpm.sock"

	# Create log directory if it doesn't exist
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

	# Generate Caddy configuration content
	local caddy_config=""

	# Common site block configuration
	read -r -d '' site_block_config <<- EOF
		root * ${site_path}/current/public
		encode gzip

		log {
			output file /var/log/caddy/${domain}-access.log {
				roll_size 100mb
				roll_keep 5
				roll_keep_for 720h
			}
			format json
		}

		# Serve PHP files through FPM
		php_fastcgi unix//${php_fpm_socket}

		# Serve static files directly (more efficient than passing through PHP-FPM)
		file_server
	EOF

	# Build configuration based on WWW mode
	case $www_mode in
		redirect-to-root)
			# Redirect www to non-www
			read -r -d '' caddy_config <<- EOF
				# Site: ${domain} (Redirect www -> root)
				www.${domain} {
					redir http://${domain}{uri} permanent
				}

				http://${domain} {
					${site_block_config}
				}
			EOF
			;;
		redirect-to-www)
			# Redirect non-www to www
			read -r -d '' caddy_config <<- EOF
				# Site: ${domain} (Redirect root -> www)
				${domain} {
					redir http://www.${domain}{uri} permanent
				}

				http://www.${domain} {
					${site_block_config}
				}
			EOF
			;;
		*)
			echo "Error: Invalid WWW mode: ${www_mode}" >&2
			exit 1
			;;
	esac

	# Create vhost configuration file
	local vhost_file="/etc/caddy/conf.d/sites/${domain}.caddy"

	if ! echo "$caddy_config" | run_cmd tee "$vhost_file" > /dev/null; then
		echo "Error: Failed to create Caddy configuration" >&2
		exit 1
	fi
}

#
# Service Management
# ----

#
# Reload or start Caddy and restart PHP-FPM

reload_services() {
	local php_version=$DEPLOYER_PHP_VERSION

	echo "→ Reloading services..."

	# Enable and reload/start Caddy
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

	# Restart PHP-FPM just to be sure
	if systemctl is-active --quiet "php${php_version}-fpm" 2> /dev/null; then
		if ! run_cmd systemctl restart "php${php_version}-fpm"; then
			echo "Warning: Failed to restart PHP-FPM service"
		fi
	fi
}

# ----
# Main Execution
# ----

main() {
	local domain=$DEPLOYER_SITE_DOMAIN
	local site_path="/home/deployer/sites/${domain}"

	# Execute setup tasks
	setup_site_directories "$domain"
	setup_demo_page "$domain"
	configure_caddy_vhost "$domain"
	reload_services

	# Use specified PHP version for output
	local php_version=$DEPLOYER_PHP_VERSION

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		site_path: ${site_path}
		site_configured: true
		php_version: ${php_version}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
