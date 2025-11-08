#!/usr/bin/env bash

#
# Server Installation Playbook - Debian Family (Ubuntu, Debian)
#
# Install Caddy, PHP 8.4, PHP-FPM, Git, Bun
# ----
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_DISTRO      - Exact distribution: ubuntu|debian
#   DEPLOYER_FAMILY      - Distribution family: debian
#   DEPLOYER_PERMS       - Permissions: root|sudo
#
# Returns YAML with:
#   - status: success
#   - distro: detected distribution
#   - php_version: installed PHP version
#   - caddy_version: installed Caddy version
#   - git_version: installed Git version
#   - bun_version: installed Bun version
#   - tasks_completed: list of completed tasks
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_FAMILY ]] && echo "Error: DEPLOYER_FAMILY required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

#
# Helpers
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

#
# Installation Functions
# ----

install_all_packages() {
	echo "✓ Installing all packages..."

	case $DEPLOYER_DISTRO in
		ubuntu)
			# Update package lists FIRST
			echo "✓ Updating package lists..."
			if ! apt_get_with_retry update -q; then
				echo "Error: Failed to update package lists" >&2
				exit 1
			fi

			# Install prerequisites (now that package lists are updated)
			echo "✓ Installing prerequisites..."
			if ! apt_get_with_retry install -y -q curl software-properties-common; then
				echo "Error: Failed to install prerequisites" >&2
				exit 1
			fi

			# Add all repositories (now that prerequisites are installed)
			echo "✓ Setting up repositories..."

			# Caddy repository (curl is now available)
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

			# PHP PPA (Ubuntu only)
			if ! grep -qr "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2> /dev/null; then
				if ! run_cmd env DEBIAN_FRONTEND=noninteractive add-apt-repository -y ppa:ondrej/php 2>&1; then
					echo "Error: Failed to add PHP PPA" >&2
					exit 1
				fi
			fi

			# Update package lists again (after adding repositories)
			echo "✓ Updating package lists..."
			if ! apt_get_with_retry update -q; then
				echo "Error: Failed to update package lists" >&2
				exit 1
			fi

			# Install remaining packages in batched groups
			echo "✓ Installing system utilities..."
			if ! apt_get_with_retry install -y -q unzip; then
				echo "Error: Failed to install system utilities" >&2
				exit 1
			fi

			echo "✓ Installing main packages..."
			if ! apt_get_with_retry install -y -q caddy git rsync; then
				echo "Error: Failed to install main packages" >&2
				exit 1
			fi

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
			;;
		debian)
			# Update package lists FIRST
			echo "✓ Updating package lists..."
			if ! apt_get_with_retry update -q; then
				echo "Error: Failed to update package lists" >&2
				exit 1
			fi

			# Install prerequisites (now that package lists are updated)
			echo "✓ Installing prerequisites..."
			if ! apt_get_with_retry install -y -q curl apt-transport-https lsb-release ca-certificates; then
				echo "Error: Failed to install prerequisites" >&2
				exit 1
			fi

			# Add all repositories (now that prerequisites are installed)
			echo "✓ Setting up repositories..."

			# Caddy repository (curl is now available)
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

			# Sury PHP repository (Debian native - NOT a PPA)
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

			# Update package lists again (after adding repositories)
			echo "✓ Updating package lists..."
			if ! apt_get_with_retry update -q; then
				echo "Error: Failed to update package lists" >&2
				exit 1
			fi

			# Install remaining packages in batched groups
			echo "✓ Installing system utilities..."
			if ! apt_get_with_retry install -y -q unzip; then
				echo "Error: Failed to install system utilities" >&2
				exit 1
			fi

			echo "✓ Installing main packages..."
			if ! apt_get_with_retry install -y -q caddy git rsync; then
				echo "Error: Failed to install main packages" >&2
				exit 1
			fi

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
			;;
	esac

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

#
# Main Execution
# ----

main() {
	local php_version caddy_version bun_version git_version

	# Execute installation tasks
	install_all_packages
	install_bun
	validate_php_version

	# Get versions
	php_version=$(php -r "echo PHP_VERSION;" 2> /dev/null || echo "unknown")
	caddy_version=$(caddy version 2> /dev/null | head -n1 | awk '{print $1}' || echo "unknown")
	git_version=$(git --version 2> /dev/null | awk '{print $3}' || echo "unknown")
	bun_version=$(bun --version 2> /dev/null || echo "unknown")

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		distro: $DEPLOYER_DISTRO
		php_version: $php_version
		caddy_version: $caddy_version
		git_version: $git_version
		bun_version: $bun_version
		tasks_completed:
		  - install_caddy
		  - install_php
		  - install_extensions
		  - configure_php_fpm
		  - install_git
		  - install_rsync
		  - install_bun
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
