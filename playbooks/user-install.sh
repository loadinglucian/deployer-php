#!/usr/bin/env bash

#
# User Installation
#
# Creates deployer user, generates SSH keys, and configures group memberships.
#
# Output:
#   status: success
#   deploy_public_key: ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAbc...xyz deployer@server-name
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
		echo "→ Creating ${deployer_ssh_dir} directory..."
		if ! run_cmd mkdir -p "$deployer_ssh_dir"; then
			echo "Error: Failed to create .ssh directory" >&2
			exit 1
		fi
	fi

	if [[ -n ${DEPLOYER_KEY_PRIVATE:-} && -n ${DEPLOYER_KEY_PUBLIC:-} ]]; then
		echo "→ Installing custom SSH key pair..."

		if ! echo "$DEPLOYER_KEY_PRIVATE" | base64 -d | run_cmd tee "$private_key" > /dev/null; then
			echo "Error: Failed to write private key" >&2
			exit 1
		fi

		if ! echo "$DEPLOYER_KEY_PUBLIC" | base64 -d | run_cmd tee "$public_key" > /dev/null; then
			echo "Error: Failed to write public key" >&2
			exit 1
		fi
	else
		if ! run_cmd test -f "$private_key"; then
			echo "→ Generating SSH key pair..."
			if ! run_cmd ssh-keygen -t ed25519 -C "deployer@${DEPLOYER_SERVER_NAME}" -f "$private_key" -N ""; then
				echo "Error: Failed to generate SSH key pair" >&2
				exit 1
			fi
		fi
	fi

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

	setup_deployer "$deployer_home"

	if ! deploy_public_key=$(run_cmd cat "${deployer_home}/.ssh/id_ed25519.pub" 2>&1); then
		echo "Error: Failed to read deploy public key at ${deployer_home}/.ssh/id_ed25519.pub" >&2
		exit 1
	fi

	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		deploy_public_key: $deploy_public_key
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
