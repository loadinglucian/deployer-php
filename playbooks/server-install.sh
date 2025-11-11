#!/usr/bin/env bash

#
# Server Installation Playbook - Ubuntu/Debian Only
#
# Install Caddy, Git, Bun, and setup deploy user
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Both distributions use apt package manager and follow debian conventions.
#
# Note: PHP installation is handled by a separate playbook (server-install-php.sh)
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_DISTRO      - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS       - Permissions: root|sudo
#   DEPLOYER_SERVER_NAME - Server name for deploy key generation
#
# Returns YAML with:
#   - status: success
#   - distro: detected distribution
#   - caddy_version: installed Caddy version
#   - git_version: installed Git version
#   - bun_version: installed Bun version
#   - deploy_public_key: public key for git deployments
#   - tasks_completed: list of completed tasks
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
# Installation Functions
# ----

#
# Repository Setup
# ----

#
# Setup distribution-specific repositories

setup_repositories() {
	echo "✓ Setting up repositories..."

	# Caddy repository (same for both Ubuntu and Debian)
	if ! [[ -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg ]]; then
		if ! curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | run_cmd gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg; then
			echo "Error: Failed to add Caddy GPG key" >&2
			exit 1
		fi
	fi

	if ! [[ -f /etc/apt/sources.list.d/caddy-stable.list ]]; then
		if ! curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | run_cmd tee /etc/apt/sources.list.d/caddy-stable.list > /dev/null; then
			echo "Error: Failed to add Caddy repository" >&2
			exit 1
		fi
	fi
}

#
# Package Installation
# ----

#
# Install all required packages (Caddy, PHP, Git, system utilities)

install_all_packages() {
	echo "✓ Installing all packages..."

	# Update package lists
	echo "✓ Updating package lists..."
	if ! apt_get_with_retry update -q; then
		echo "Error: Failed to update package lists" >&2
		exit 1
	fi

	# Install prerequisites based on distribution
	echo "✓ Installing prerequisites..."
	case $DEPLOYER_DISTRO in
		ubuntu)
			if ! apt_get_with_retry install -y -q curl software-properties-common; then
				echo "Error: Failed to install prerequisites" >&2
				exit 1
			fi
			;;
		debian)
			if ! apt_get_with_retry install -y -q curl apt-transport-https lsb-release ca-certificates; then
				echo "Error: Failed to install prerequisites" >&2
				exit 1
			fi
			;;
	esac

	# Setup repositories (requires prerequisites)
	setup_repositories

	# Update package lists again (after adding repositories)
	echo "✓ Updating package lists..."
	if ! apt_get_with_retry update -q; then
		echo "Error: Failed to update package lists" >&2
		exit 1
	fi

	# Install system utilities
	echo "✓ Installing system utilities..."
	if ! apt_get_with_retry install -y -q unzip; then
		echo "Error: Failed to install system utilities" >&2
		exit 1
	fi

	# Install main packages
	echo "✓ Installing main packages..."
	if ! apt_get_with_retry install -y -q caddy git rsync; then
		echo "Error: Failed to install main packages" >&2
		exit 1
	fi
}

#
# Install Bun runtime

install_bun() {
	if command -v bun > /dev/null 2>&1; then
		echo "✓ Bun already installed"
		return 0
	fi

	echo "✓ Installing Bun..."

	# Install Bun system-wide to /usr/local (unzip is now installed in batched packages)
	if ! curl -fsSL https://bun.sh/install | run_cmd env BUN_INSTALL=/usr/local bash; then
		echo "Error: Failed to install Bun" >&2
		exit 1
	fi
}

#
# Caddy Configuration
# ----

#
# Setup Caddy configuration structure

setup_caddy_structure() {
	echo "✓ Setting up Caddy configuration structure..."

	# Create directory structure
	if ! run_cmd mkdir -p /etc/caddy/conf.d/sites; then
		echo "Error: Failed to create Caddy config directories" >&2
		exit 1
	fi

	# Create main Caddyfile with global settings and imports
	if ! run_cmd tee /etc/caddy/Caddyfile > /dev/null <<- 'EOF'; then
		{
			metrics

			log {
				output file /var/log/caddy/access.log
				format json
			}
		}

		# Import localhost-only endpoints (monitoring, status pages)
		import conf.d/localhost.caddy

		# Import all site configurations
		import conf.d/sites/*.caddy
	EOF
		echo "Error: Failed to create main Caddyfile" >&2
		exit 1
	fi

	# Create localhost.caddy - monitoring endpoints only accessible via localhost
	# (PHP-FPM status endpoint will be added by PHP installation playbook)
	if ! run_cmd tee /etc/caddy/conf.d/localhost.caddy > /dev/null <<- 'EOF'; then
		# PHP-FPM status endpoints - localhost only (not accessible from internet)
		http://localhost:9001 {
			#### DEPLOYER-PHP CONFIG, WARRANTY VOID IF REMOVED :) ####
		}
	EOF
		echo "Error: Failed to create localhost.caddy" >&2
		exit 1
	fi
}

#
# Deployer User Setup
# ----

#
# Ensure deployer user exists

ensure_deployer_user() {
	if id -u deployer > /dev/null 2>&1; then
		echo "✓ Deployer user already exists"
		return 0
	fi

	echo "✓ Creating deployer user..."
	if ! run_cmd useradd -m -s /bin/bash deployer; then
		echo "Error: Failed to create deployer user" >&2
		exit 1
	fi
}

#
# Configure group memberships for file access

configure_deployer_groups() {
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

}

#
# Setup deploy user with proper home directory and permissions

setup_deploy_user() {
	ensure_deployer_user

	local deployer_home
	deployer_home=$(getent passwd deployer | cut -d: -f6)

	if [[ -z $deployer_home ]]; then
		echo "Error: Unable to determine deployer home directory" >&2
		exit 1
	fi

	if ! run_cmd test -d "$deployer_home"; then
		if ! run_cmd mkdir -p "$deployer_home"; then
			echo "Error: Failed to create deployer home directory" >&2
			exit 1
		fi
	fi

	if ! run_cmd chown deployer:deployer "$deployer_home"; then
		echo "Error: Failed to set ownership on deployer home directory" >&2
		exit 1
	fi

	if ! run_cmd chmod 750 "$deployer_home"; then
		echo "Error: Failed to set permissions on deployer home directory" >&2
		exit 1
	fi

	configure_deployer_groups
}

#
# Deploy Key Setup
# ----

#
# Generate SSH deploy key for git operations

setup_deploy_key() {
	echo "✓ Setting up deploy key..."

	setup_deploy_user

	local deployer_home
	deployer_home=$(getent passwd deployer | cut -d: -f6)
	local deployer_ssh_dir
	deployer_ssh_dir="${deployer_home}/.ssh"
	local private_key
	private_key="${deployer_ssh_dir}/id_ed25519"
	local public_key
	public_key="${deployer_ssh_dir}/id_ed25519.pub"

	# Create .ssh directory if it doesn't exist
	if ! run_cmd test -d "$deployer_ssh_dir"; then
		if ! run_cmd mkdir -p "$deployer_ssh_dir"; then
			echo "Error: Failed to create .ssh directory" >&2
			exit 1
		fi
	fi

	# Generate key pair if it doesn't exist
	if ! run_cmd test -f "$private_key"; then
		echo "✓ Generating SSH key pair..."
		if ! run_cmd ssh-keygen -t ed25519 -C "deployer@${DEPLOYER_SERVER_NAME}" -f "$private_key" -N ""; then
			echo "Error: Failed to generate SSH key pair" >&2
			exit 1
		fi
	else
		echo "✓ SSH key pair already exists"
	fi

	# Set proper ownership and permissions
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

setup_deploy_directories() {
	if ! run_cmd test -d /home/deployer; then
		echo "Error: Deployer home directory missing" >&2
		exit 1
	fi

	# Ensure home directory permissions
	if ! run_cmd chmod 750 /home/deployer; then
		echo "Error: Failed to set permissions on deployer home" >&2
		exit 1
	fi

	# Ensure demo directory structure ownership if present
	if run_cmd test -d /home/deployer/demo; then
		if ! run_cmd chown -R deployer:deployer /home/deployer/demo; then
			echo "Error: Failed to set ownership on demo directory" >&2
			exit 1
		fi

		if ! run_cmd chmod 750 /home/deployer/demo; then
			echo "Error: Failed to set permissions on demo directory" >&2
			exit 1
		fi

		if run_cmd test -d /home/deployer/demo/public; then
			if ! run_cmd chmod 750 /home/deployer/demo/public; then
				echo "Error: Failed to set permissions on public directory" >&2
				exit 1
			fi

			if run_cmd test -f /home/deployer/demo/public/index.php; then
				if ! run_cmd chmod 640 /home/deployer/demo/public/index.php; then
					echo "Error: Failed to set permissions on index.php" >&2
					exit 1
				fi
			fi
		fi
	fi
}

#
# Validation
# ----

# ----
# Main Execution
# ----

main() {
	local caddy_version bun_version git_version deploy_public_key

	# Execute installation tasks
	install_all_packages
	install_bun
	setup_caddy_structure
	setup_deploy_key
	setup_deploy_directories

	# Get versions and public key
	caddy_version=$(caddy version 2> /dev/null | head -n1 | awk '{print $1}' || echo "unknown")
	git_version=$(git --version 2> /dev/null | awk '{print $3}' || echo "unknown")
	bun_version=$(bun --version 2> /dev/null || echo "unknown")
	deploy_public_key=$(run_cmd cat /home/deployer/.ssh/id_ed25519.pub 2> /dev/null || echo "unknown")

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		distro: $DEPLOYER_DISTRO
		caddy_version: $caddy_version
		git_version: $git_version
		bun_version: $bun_version
		deploy_public_key: $deploy_public_key
		tasks_completed:
		  - install_caddy
		  - setup_caddy_structure
		  - install_git
		  - install_rsync
		  - install_bun
		  - setup_deploy_user
		  - setup_deploy_key
		  - setup_deploy_directories
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
