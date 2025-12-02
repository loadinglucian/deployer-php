#!/usr/bin/env bash

#
# Site HTTPS Playbook - Ubuntu/Debian Only
#
# Enable HTTPS for an existing site using Caddy's automatic certificates.
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Requires site to be already added.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE  - Output file path
#   DEPLOYER_DISTRO       - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS        - Permissions: root|sudo
#   DEPLOYER_SITE_DOMAIN  - Site domain name
#   DEPLOYER_PHP_VERSION  - PHP version to use (preserved)
#   DEPLOYER_WWW_MODE     - WWW handling mode (preserved)
#
# Returns YAML with:
#   - status: success
#   - https_enabled: true
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
# Helper Functions
# ----

#
# Update Caddy Configuration
# ----

update_caddy_config() {
	local domain=$1
	local site_path="/home/deployer/sites/${domain}"
	local www_mode=$DEPLOYER_WWW_MODE
	local php_version=$DEPLOYER_PHP_VERSION
	local php_fpm_socket="/run/php/php${php_version}-fpm.sock"
	local vhost_file="/etc/caddy/conf.d/sites/${domain}.caddy"

	echo "→ Updating Caddy configuration for HTTPS..."

	if ! run_cmd test -f "$vhost_file"; then
		echo "Error: Site configuration file not found: $vhost_file" >&2
		exit 1
	fi

	# Common site block configuration
	# Note: No http:// prefix here triggers Auto HTTPS
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

		# Serve static files directly
		file_server
	EOF

	# Build configuration based on WWW mode
	local caddy_config=""

	case $www_mode in
		redirect-to-root)
			# Redirect www to non-www
			read -r -d '' caddy_config <<- EOF
				# Site: ${domain} (Redirect www -> root)
				www.${domain} {
					redir https://${domain}{uri} permanent
				}

				${domain} {
					${site_block_config}
				}
			EOF
			;;
		redirect-to-www)
			# Redirect non-www to www
			read -r -d '' caddy_config <<- EOF
				# Site: ${domain} (Redirect root -> www)
				${domain} {
					redir https://www.${domain}{uri} permanent
				}

				www.${domain} {
					${site_block_config}
				}
			EOF
			;;
		*)
			echo "Error: Invalid WWW mode: ${www_mode}" >&2
			exit 1
			;;
	esac

	if ! echo "$caddy_config" | run_cmd tee "$vhost_file" > /dev/null; then
		echo "Error: Failed to update Caddy configuration" >&2
		exit 1
	fi
}

#
# Reload Services
# ----

reload_services() {
	echo "→ Reloading Caddy..."
	if ! run_cmd systemctl reload caddy; then
		echo "Error: Failed to reload Caddy" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	local domain=$DEPLOYER_SITE_DOMAIN

	update_caddy_config "$domain"
	reload_services

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		https_enabled: true
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
