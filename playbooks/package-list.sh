#!/usr/bin/env bash

#
# Package List
#
# Configures package repositories and optionally gathers available PHP versions.
#
# Output:
#   status: success
#   repos_configured: true
#
# Output (with DEPLOYER_GATHER_PHP=true):
#   status: success
#   repos_configured: true
#   php:
#     "8.4":
#       extensions: [cli, fpm, mysql, curl, mbstring]
#     "8.3":
#       extensions: [cli, fpm, mysql, curl, mbstring]
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Helper Functions
# ----

#
# Smart apt update with timestamp-based throttling
#
# Arguments:
#   $1 - force (optional): true to bypass throttling, false/empty for normal behavior
#
# Returns:
#   0 on success, 1 on failure

smart_apt_update() {
	local force=${1:-false}
	local timestamp_file="/tmp/deployer-apt-last-update"
	local threshold_seconds=$((24 * 60 * 60)) # 24 hours
	local now current_timestamp age

	now=$(date +%s)

	# Check if we need to update
	if [[ $force == false && -f $timestamp_file ]]; then
		current_timestamp=$(cat "$timestamp_file" 2> /dev/null || echo "0")
		age=$((now - current_timestamp))

		if ((age < threshold_seconds)); then
			echo "→ Using cached package list (cached $((age / 3600)) hours ago)..."
			return 0
		fi
	fi

	# Perform update
	echo "→ Updating package lists..."
	if ! apt_get_with_retry update; then
		echo "Error: Failed to update package lists" >&2
		return 1
	fi

	# Update timestamp
	echo "$now" > "$timestamp_file"
}

#
# Get PHP versions and extensions with caching
#
# Arguments:
#   $1 - force (optional): true to bypass cache and re-detect
#
# Side effects:
#   Sets PHP_CACHE_YAML with the YAML structure (or empty string)

get_php_with_cache() {
	PHP_CACHE_YAML=""
	local force=${1:-false}
	local cache_file="/tmp/deployer-php-cache"
	local threshold_seconds=$((24 * 60 * 60)) # 24 hours
	local now current_timestamp age

	# Check if PHP gathering is requested
	if [[ $DEPLOYER_GATHER_PHP != 'true' ]]; then
		return 0
	fi

	now=$(date +%s)

	# Check if we can use cache
	if [[ $force == false && -f $cache_file ]]; then
		# Read timestamp from first line of cache
		current_timestamp=$(head -n1 "$cache_file" 2> /dev/null || echo "0")
		age=$((now - current_timestamp))

		if ((age < threshold_seconds)); then
			echo "→ Using cached PHP version and extensions list (cached $((age / 3600)) hours ago)..."
			# Return cached YAML (skip first line which is timestamp)
			PHP_CACHE_YAML=$(tail -n +2 "$cache_file" 2> /dev/null || printf '')
			return 0
		fi
	fi

	# Perform fresh detection
	echo "→ Detecting available PHP versions..."

	local php_versions
	php_versions=$(apt-cache search "^php[0-9]+\.[0-9]+-fpm$" 2> /dev/null | grep -oP 'php\K[0-9]+\.[0-9]+' | sort -V -u)

	if [[ -z $php_versions ]]; then
		echo "Error: No PHP versions found in repositories" >&2
		exit 1
	fi

	# Build YAML structure
	local yaml_output="php:"

	echo "→ Detecting available PHP extensions..."
	for version in $php_versions; do
		local extensions
		extensions=$(apt-cache search "^php${version}-" 2> /dev/null | grep -oP "php${version}-\K[a-z0-9]+" | sort -u)

		if [[ -z $extensions ]]; then
			continue
		fi

		yaml_output="${yaml_output}\n  \"${version}\":"
		yaml_output="${yaml_output}\n    extensions:"

		for ext in $extensions; do
			yaml_output="${yaml_output}\n      - ${ext}"
		done
	done

	# Cache the results (timestamp on first line, YAML on subsequent lines)
	{
		echo "$now"
		echo -e "$yaml_output"
	} > "$cache_file"

	# Store the YAML for callers
	PHP_CACHE_YAML="$yaml_output"
}

# ----
# Main Execution
# ----

main() {
	local repo_added=false

	#
	# Initial apt update
	# ----

	if ! smart_apt_update; then
		exit 1
	fi

	#
	# Caddy repository (same for both Ubuntu and Debian)
	# ----

	if ! [[ -f /usr/share/keyrings/caddy-stable-archive-keyring.gpg ]]; then
		echo "→ Adding Caddy GPG key..."
		if ! curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' | run_cmd gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg; then
			echo "Error: Failed to add Caddy GPG key" >&2
			exit 1
		fi
		repo_added=true
	fi

	if ! [[ -f /etc/apt/sources.list.d/caddy-stable.list ]]; then
		echo "→ Adding Caddy repository..."
		if ! curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' | run_cmd tee /etc/apt/sources.list.d/caddy-stable.list > /dev/null; then
			echo "Error: Failed to add Caddy repository" >&2
			exit 1
		fi
		repo_added=true
	fi

	#
	# PHP repository
	# ----

	case $DEPLOYER_DISTRO in
		ubuntu)

			#
			# PHP PPA (Ubuntu only)

			if ! grep -qr "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d/ 2> /dev/null; then
				echo "→ Adding PHP PPA..."
				if ! run_cmd env DEBIAN_FRONTEND=noninteractive add-apt-repository -y ppa:ondrej/php 2>&1; then
					echo "Error: Failed to add PHP PPA" >&2
					exit 1
				fi
				repo_added=true
			fi
			;;
		debian)

			#
			# Sury PHP repository (Debian only)

			if ! [[ -f /usr/share/keyrings/php-sury-archive-keyring.gpg ]]; then
				echo "→ Adding PHP Sury GPG key..."
				if ! curl -fsSL 'https://packages.sury.org/php/apt.gpg' | run_cmd gpg --batch --yes --dearmor -o /usr/share/keyrings/php-sury-archive-keyring.gpg; then
					echo "Error: Failed to add Sury PHP GPG key" >&2
					exit 1
				fi
				repo_added=true
			fi

			if ! [[ -f /etc/apt/sources.list.d/php-sury.list ]]; then
				echo "→ Adding PHP Sury repository..."
				local debian_codename
				debian_codename=$(lsb_release -sc)
				if ! echo "deb [signed-by=/usr/share/keyrings/php-sury-archive-keyring.gpg] https://packages.sury.org/php/ ${debian_codename} main" | run_cmd tee /etc/apt/sources.list.d/php-sury.list > /dev/null; then
					echo "Error: Failed to add Sury PHP repository" >&2
					exit 1
				fi
				repo_added=true
			fi
			;;
	esac

	#
	# Update apt again, only if we added new repositories
	# ----

	if [[ $repo_added == true ]]; then
		if ! smart_apt_update true; then
			exit 1
		fi
	fi

	#
	# Detect PHP versions and extensions (optional, with caching)
	# ----

	local yaml_php=""

	# Pass force=true if repos were added to invalidate cache
	if [[ $repo_added == true ]]; then
		get_php_with_cache true
	else
		get_php_with_cache
	fi

	yaml_php="$PHP_CACHE_YAML"

	#
	# Write output YAML
	# ----

	if [[ -n $yaml_php ]]; then
		{
			echo "status: success"
			echo "repos_configured: true"
			printf '%b\n' "$yaml_php"
		} > "$DEPLOYER_OUTPUT_FILE"
	else
		{
			echo "status: success"
			echo "repos_configured: true"
		} > "$DEPLOYER_OUTPUT_FILE"
	fi

	if [[ ! -f $DEPLOYER_OUTPUT_FILE ]]; then
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
