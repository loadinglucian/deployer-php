#!/usr/bin/env bash

#
# Server Installation Playbook - Ubuntu/Debian Only
#
# Install Caddy, PHP 8.4, PHP-FPM, Git, Bun
# ----
#
# This playbook only supports Ubuntu and Debian distributions (debian family).
# Both distributions use apt package manager and follow debian conventions.
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
#   - php_version: installed PHP version
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

# ----
# Helpers
# ----

#
# Permission Management
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
# Package Management
# ----

#
# Wait for dpkg lock to be released

wait_for_dpkg_lock() {
	local max_wait=60
	local waited=0
	local lock_found=false

	# Check multiple times to catch the lock even in race conditions
	while ((waited < max_wait)); do
		# Try to acquire the lock by checking if we can open it
		if fuser /var/lib/dpkg/lock-frontend > /dev/null 2>&1 \
			|| fuser /var/lib/dpkg/lock > /dev/null 2>&1 \
			|| fuser /var/lib/apt/lists/lock > /dev/null 2>&1; then
			lock_found=true
			echo "✓ Waiting for package manager lock to be released..."
			sleep 2
			waited=$((waited + 2))
		else
			# Lock not held, but wait a bit to ensure it's really released
			if [[ $lock_found == true ]]; then
				# Was locked before, give it extra time
				sleep 2
			else
				# Never saw lock, just a small delay
				sleep 1
			fi
			return 0
		fi
	done

	echo "Error: Timeout waiting for dpkg lock to be released" >&2
	return 1
}

#
# apt-get with retry

apt_get_with_retry() {
	local max_attempts=5
	local attempt=1
	local wait_time=10
	local output

	while ((attempt <= max_attempts)); do
		# Capture output to check for lock errors
		output=$(run_cmd apt-get "$@" 2>&1)
		local exit_code=$?

		if ((exit_code == 0)); then
			[[ -n $output ]] && echo "$output"
			return 0
		fi

		# Only retry on lock-related errors
		if echo "$output" | grep -qE 'Could not get lock|dpkg.*lock|Unable to acquire'; then
			if ((attempt < max_attempts)); then
				echo "✓ Package manager locked, waiting ${wait_time}s before retry (attempt ${attempt}/${max_attempts})..."
				sleep "$wait_time"
				wait_time=$((wait_time + 5))
				attempt=$((attempt + 1))
				wait_for_dpkg_lock || true
			else
				echo "$output" >&2
				return "$exit_code"
			fi
		else
			# Non-lock error, fail immediately
			echo "$output" >&2
			return "$exit_code"
		fi
	done

	return 1
}

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

	# PHP repository (distribution-specific)
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

	# Install PHP 8.4
	echo "✓ Installing PHP 8.4..."
	if ! apt_get_with_retry install -y -q --no-install-recommends \
		php8.4-cli \
		php8.4-fpm \
		php8.4-common \
		php8.4-opcache \
		php8.4-bcmath \
		php8.4-curl \
		php8.4-mbstring \
		php8.4-xml \
		php8.4-zip \
		php8.4-gd \
		php8.4-intl \
		php8.4-soap 2>&1; then
		echo "Error: Failed to install PHP 8.4 packages" >&2
		exit 1
	fi

	# Configure PHP-FPM
	echo "✓ Configuring PHP-FPM..."

	# Set socket ownership so Caddy can access it
	if ! run_cmd sed -i 's/^;listen.owner = .*/listen.owner = caddy/' /etc/php/8.4/fpm/pool.d/www.conf; then
		echo "Error: Failed to set PHP-FPM socket owner" >&2
		exit 1
	fi
	if ! run_cmd sed -i 's/^;listen.group = .*/listen.group = caddy/' /etc/php/8.4/fpm/pool.d/www.conf; then
		echo "Error: Failed to set PHP-FPM socket group" >&2
		exit 1
	fi
	if ! run_cmd sed -i 's/^;listen.mode = .*/listen.mode = 0660/' /etc/php/8.4/fpm/pool.d/www.conf; then
		echo "Error: Failed to set PHP-FPM socket mode" >&2
		exit 1
	fi

	# Enable PHP-FPM status page
	if ! run_cmd sed -i 's/^;pm.status_path = .*/pm.status_path = \/fpm-status/' /etc/php/8.4/fpm/pool.d/www.conf; then
		echo "Error: Failed to enable PHP-FPM status page" >&2
		exit 1
	fi

	if ! systemctl is-enabled --quiet php8.4-fpm 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet php8.4-fpm; then
			echo "Error: Failed to enable PHP-FPM service" >&2
			exit 1
		fi
	fi
	if ! systemctl is-active --quiet php8.4-fpm 2> /dev/null; then
		if ! run_cmd systemctl start php8.4-fpm; then
			echo "Error: Failed to start PHP-FPM service" >&2
			exit 1
		fi
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
	if ! run_cmd tee /etc/caddy/conf.d/localhost.caddy > /dev/null <<- 'EOF'; then
		# PHP-FPM status endpoint - localhost only (not accessible from internet)
		http://localhost:9001 {
			handle {
				reverse_proxy unix//run/php/php8.4-fpm.sock {
					transport fastcgi {
						env SCRIPT_FILENAME /fpm-status
						env SCRIPT_NAME /fpm-status
					}
				}
			}
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

	# Add www-data (PHP-FPM user) to deployer group so it can access files
	if id -u www-data > /dev/null 2>&1; then
		if ! id -nG www-data 2> /dev/null | grep -qw deployer; then
			echo "✓ Adding www-data user to deployer group..."
			if ! run_cmd usermod -aG deployer www-data; then
				echo "Error: Failed to add www-data to deployer group" >&2
				exit 1
			fi

			# Restart PHP-FPM so it picks up the new group membership
			if systemctl is-active --quiet php8.4-fpm 2> /dev/null; then
				echo "✓ Restarting PHP-FPM to apply group membership..."
				if ! run_cmd systemctl restart php8.4-fpm; then
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

#
# Validate PHP version meets minimum requirements

validate_php_version() {
	local php_version
	php_version=$(php -r "echo PHP_VERSION;" 2> /dev/null || echo "unknown")

	if [[ $php_version == "unknown" ]]; then
		echo "Error: PHP installation failed or PHP not in PATH" >&2
		exit 1
	fi

	# Extract major.minor version
	local php_major_minor
	php_major_minor=$(echo "$php_version" | cut -d. -f1,2)

	# Check if below 8.3 using awk
	if awk "BEGIN {exit !($php_major_minor < 8.3)}"; then
		echo "Error: PHP $php_version is below minimum required version 8.3" >&2
		exit 1
	fi

	echo "✓ PHP $php_version installed (meets minimum 8.3)"
}

# ----
# Main Execution
# ----

main() {
	local php_version caddy_version bun_version git_version deploy_public_key

	# Execute installation tasks
	install_all_packages
	install_bun
	setup_caddy_structure
	validate_php_version
	setup_deploy_key
	setup_deploy_directories

	# Get versions and public key
	php_version=$(php -r "echo PHP_VERSION;" 2> /dev/null || echo "unknown")
	caddy_version=$(caddy version 2> /dev/null | head -n1 | awk '{print $1}' || echo "unknown")
	git_version=$(git --version 2> /dev/null | awk '{print $3}' || echo "unknown")
	bun_version=$(bun --version 2> /dev/null || echo "unknown")
	deploy_public_key=$(run_cmd cat /home/deployer/.ssh/id_ed25519.pub 2> /dev/null || echo "unknown")

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		distro: $DEPLOYER_DISTRO
		php_version: $php_version
		caddy_version: $caddy_version
		git_version: $git_version
		bun_version: $bun_version
		deploy_public_key: $deploy_public_key
		tasks_completed:
		  - install_caddy
		  - setup_caddy_structure
		  - install_php
		  - install_extensions
		  - configure_php_fpm
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
