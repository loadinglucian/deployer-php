#!/usr/bin/env bash

#
# Deployer User Setup Playbook
#
# Creates deployer user, generates SSH keys, and configures permissions
# ----
#
# This playbook handles all deployer user-related configuration including:
# - User creation with home directory
# - SSH key pair generation for git deployments
# - Group memberships (adding caddy and www-data to deployer group)
# - Directory permissions for deployer home
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_PERMS       - Permissions: root|sudo
#   DEPLOYER_SERVER_NAME - Server name for deploy key generation
#
# Returns YAML with:
#   - status: success
#   - deploy_public_key: public key for git deployments
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SERVER_NAME ]] && echo "Error: DEPLOYER_SERVER_NAME required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Setup Functions
# ----

#
# Setup deployer user
# ----

setup_deployer() {
	local deployer_home=$1

	# Ensure home directory has correct permissions (default is usually 755)
	if ! run_cmd chmod 750 "$deployer_home"; then
		echo "Error: Failed to set permissions on deployer home directory" >&2
		exit 1
	fi

	#
	# Configure group memberships
	# ----

	# Add caddy user to deployer group for file access
	if id -u caddy > /dev/null 2>&1; then
		if ! id -nG caddy 2> /dev/null | grep -qw deployer; then
			echo "→ Adding caddy user to deployer group..."
			if ! run_cmd usermod -aG deployer caddy; then
				echo "Error: Failed to add caddy to deployer group" >&2
				exit 1
			fi

			if systemctl is-active --quiet caddy 2> /dev/null; then
				echo "→ Restarting Caddy to apply group membership..."
				if ! run_cmd systemctl restart caddy; then
					echo "Error: Failed to restart Caddy" >&2
					exit 1
				fi
			fi
		fi
	fi

	# Add www-data (PHP-FPM user) to deployer group for file access
	if id -u www-data > /dev/null 2>&1; then
		if ! id -nG www-data 2> /dev/null | grep -qw deployer; then
			echo "→ Adding www-data user to deployer group..."
			if ! run_cmd usermod -aG deployer www-data; then
				echo "Error: Failed to add www-data to deployer group" >&2
				exit 1
			fi

			# Restart all active PHP-FPM services to apply group membership
			local fpm_services
			fpm_services=$(systemctl list-units --type=service --state=active 'php*-fpm.service' --no-legend 2> /dev/null | awk '{print $1}')

			if [[ -n $fpm_services ]]; then
				echo "→ Restarting PHP-FPM services to apply group membership..."
				while IFS= read -r service; do
					if ! run_cmd systemctl restart "$service"; then
						echo "Warning: Failed to restart $service"
					fi
				done <<< "$fpm_services"
			fi
		fi
	fi

	#
	# Setup SSH deploy key
	# ----

	local deployer_ssh_dir="${deployer_home}/.ssh"
	local private_key="${deployer_ssh_dir}/id_ed25519"
	local public_key="${deployer_ssh_dir}/id_ed25519.pub"

	if ! run_cmd test -d "$deployer_ssh_dir"; then
		echo "→ Creating .ssh directory..."
		if ! run_cmd mkdir -p "$deployer_ssh_dir"; then
			echo "Error: Failed to create .ssh directory" >&2
			exit 1
		fi
	fi

	if ! run_cmd test -f "$private_key"; then
		echo "→ Generating SSH key pair..."
		if ! run_cmd ssh-keygen -t ed25519 -C "deployer@${DEPLOYER_SERVER_NAME}" -f "$private_key" -N ""; then
			echo "Error: Failed to generate SSH key pair" >&2
			exit 1
		fi
	else
		echo "→ SSH key pair already exists"
	fi

	# Set ownership and permissions
	if ! run_cmd chown -R deployer:deployer "$deployer_ssh_dir"; then
		echo "Error: Failed to set ownership on .ssh directory" >&2
		exit 1
	fi

	if ! run_cmd chmod 700 "$deployer_ssh_dir"; then
		echo "Error: Failed to set permissions on .ssh directory" >&2
		exit 1
	fi

	if ! run_cmd chmod 600 "$private_key"; then
		echo "Error: Failed to set permissions on private key" >&2
		exit 1
	fi

	if ! run_cmd chmod 644 "$public_key"; then
		echo "Error: Failed to set permissions on public key" >&2
		exit 1
	fi
}

#
# Ensure proper permissions on deploy directories
# ----

setup_deploy_directories() {
	local deployer_home=$1

	if ! run_cmd test -d "$deployer_home"; then
		echo "Error: Deployer home directory missing" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	local deploy_public_key
	local deployer_home

	# Create deployer user if it doesn't exist
	if ! id -u deployer > /dev/null 2>&1; then
		echo "→ Creating deployer user..."
		if ! run_cmd useradd -m -s /bin/bash deployer; then
			echo "Error: Failed to create deployer user" >&2
			exit 1
		fi
	fi

	# Discover deployer home directory (must be after user creation)
	deployer_home=$(getent passwd deployer | cut -d: -f6)
	if [[ -z $deployer_home ]]; then
		echo "Error: Unable to determine deployer home directory" >&2
		exit 1
	fi

	# Execute deployer setup tasks
	setup_deployer "$deployer_home"
	setup_deploy_directories "$deployer_home"

	# Get deploy public key
	if ! deploy_public_key=$(run_cmd cat "${deployer_home}/.ssh/id_ed25519.pub" 2>&1); then
		echo "Error: Failed to read deploy public key at ${deployer_home}/.ssh/id_ed25519.pub" >&2
		exit 1
	fi

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		deploy_public_key: $deploy_public_key
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
