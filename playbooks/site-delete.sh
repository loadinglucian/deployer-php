#!/usr/bin/env bash

#
# Site Delete Playbook - Ubuntu/Debian Only
#
# Remove site files and Caddy configuration from server
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Removes site directory and Caddy vhost configuration, then reloads Caddy.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE  - Output file path
#   DEPLOYER_DISTRO       - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS        - Permissions: root|sudo
#   DEPLOYER_SITE_DOMAIN  - Site domain name
#
# Returns YAML with:
#   - status: success
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Cleanup Functions
# ----

#
# Remove Caddy vhost configuration
# ----

remove_caddy_vhost() {
	local domain=$1
	local vhost_file="/etc/caddy/conf.d/sites/${domain}.caddy"

	if run_cmd test -f "$vhost_file"; then
		echo "→ Removing Caddy configuration for ${domain}..."
		if ! run_cmd rm -f "$vhost_file"; then
			echo "Error: Failed to remove Caddy configuration" >&2
			exit 1
		fi
	fi
}

#
# Reload Caddy service
# ----

reload_caddy() {
	if systemctl is-active --quiet caddy 2> /dev/null; then
		echo "→ Reloading services..."
		if ! run_cmd systemctl reload caddy; then
			echo "Error: Failed to reload services" >&2
			exit 1
		fi
	fi
}

#
# Remove site files
# ----

remove_site_files() {
	local domain=$1
	local site_path="/home/deployer/sites/${domain}"

	if run_cmd test -d "$site_path"; then
		echo "→ Removing files for ${domain}..."
		if ! run_cmd rm -rf "$site_path"; then
			echo "Error: Failed to remove files" >&2
			exit 1
		fi
	fi
}

# ----
# Main Execution
# ----

main() {
	local domain=$DEPLOYER_SITE_DOMAIN

	# Execute cleanup tasks
	remove_caddy_vhost "$domain"
	reload_caddy
	remove_site_files "$domain"

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" << EOF; then
status: success
EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
