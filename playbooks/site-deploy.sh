#!/usr/bin/env bash

#
# Site Deploy Playbook - Ubuntu/Debian Only
#
# Deploy site using atomic releases with deployment hooks
# ----
#
# This playbook orchestrates the complete deployment process for a site:
# - Clones or updates the git repository
# - Creates a timestamped release directory
# - Exports code from the repository to the release
# - Runs deployment hooks at key stages (1-building, 2-releasing, 3-finishing)
# - Activates the new release by updating the current symlink
# - Cleans up old releases beyond the retention limit
#
# The deployment follows an atomic release structure:
#   /home/deployer/sites/{domain}/
#     ├── releases/     - Timestamped release directories
#     ├── current/      - Symlink to active release
#     ├── shared/       - Shared files across releases
#     └── repo/         - Bare git repository
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE   - Output file path (YAML)
#   DEPLOYER_DISTRO        - Server distribution (ubuntu|debian)
#   DEPLOYER_PERMS         - Permissions (root|sudo)
#   DEPLOYER_SITE_DOMAIN   - Site domain
#   DEPLOYER_SITE_REPO     - Git repository URL
#   DEPLOYER_SITE_BRANCH   - Git branch to deploy
#   DEPLOYER_PHP_VERSION   - PHP version to expose to hooks
#
# Optional Environment Variables:
#   DEPLOYER_KEEP_RELEASES - Number of releases to keep (default: 5)
#
# Returns YAML with:
#   - status: success
#   - domain: {domain}
#   - branch: {branch}
#   - release_name: {timestamp}
#   - release_path: {path}
#   - current_path: {path}
#   - keep_releases: {number}
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
[[ -z $DEPLOYER_SITE_REPO ]] && echo "Error: DEPLOYER_SITE_REPO required" && exit 1
[[ -z $DEPLOYER_SITE_BRANCH ]] && echo "Error: DEPLOYER_SITE_BRANCH required" && exit 1
[[ -z $DEPLOYER_PHP_VERSION ]] && echo "Error: DEPLOYER_PHP_VERSION required" && exit 1

DEPLOYER_KEEP_RELEASES=${DEPLOYER_KEEP_RELEASES:-5}
if ! [[ $DEPLOYER_KEEP_RELEASES =~ ^[0-9]+$ ]] || ((DEPLOYER_KEEP_RELEASES < 1)); then
	DEPLOYER_KEEP_RELEASES=5
fi
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

SITE_ROOT="/home/deployer/sites/${DEPLOYER_SITE_DOMAIN}"
RELEASE_NAME=$(date +%Y%m%d_%H%M%S)
RELEASE_PATH="${SITE_ROOT}/releases/${RELEASE_NAME}"
SHARED_PATH="${SITE_ROOT}/shared"
CURRENT_PATH="${SITE_ROOT}/current"
REPO_PATH="${SITE_ROOT}/repo"

export DEPLOYER_RELEASE_PATH="$RELEASE_PATH"
export DEPLOYER_SHARED_PATH="$SHARED_PATH"
export DEPLOYER_CURRENT_PATH="$CURRENT_PATH"
export DEPLOYER_REPO_PATH="$REPO_PATH"
export DEPLOYER_DOMAIN="$DEPLOYER_SITE_DOMAIN"
export DEPLOYER_BRANCH="$DEPLOYER_SITE_BRANCH"
export DEPLOYER_KEEP_RELEASES

PRESERVE_ENV_VARS="DEPLOYER_RELEASE_PATH,DEPLOYER_SHARED_PATH,DEPLOYER_CURRENT_PATH,DEPLOYER_REPO_PATH,DEPLOYER_DOMAIN,DEPLOYER_BRANCH,DEPLOYER_PHP_VERSION,DEPLOYER_PHP,DEPLOYER_KEEP_RELEASES,DEPLOYER_DISTRO,DEPLOYER_PERMS"

# ----
# Helper Functions
# ----

#
# Hook Management
# ----

#
# Execute deployment hook if it exists
#
# Arguments:
#   $1 - Hook name (1-building.sh, 2-releasing.sh, 3-finishing.sh)

run_hook() {
	local hook_name=$1
	local hook_path="${DEPLOYER_RELEASE_PATH}/.deployer/hooks/${hook_name}"

	if [[ ! -f $hook_path ]]; then
		return 0
	fi

	if [[ ! -x $hook_path ]]; then
		chmod +x "$hook_path" || fail "Failed to make ${hook_name} hook executable"
		run_as_deployer chmod +x "$hook_path" > /dev/null 2>&1 || true
	fi

	echo "→ Running ${hook_name} hook..."

	cd "$DEPLOYER_RELEASE_PATH" || fail "Failed to change directory to release path"

	if ! run_as_deployer "$hook_path"; then
		fail "${hook_name} hook failed"
	fi
}

#
# PHP Detection
# ----

#
# Detect and export PHP binary path for specified version
#
# Side effects:
#   Sets DEPLOYER_PHP environment variable with binary path

detect_php_binary() {
	local candidate
	if command -v "php${DEPLOYER_PHP_VERSION}" > /dev/null 2>&1; then
		candidate=$(command -v "php${DEPLOYER_PHP_VERSION}")
	elif command -v php > /dev/null 2>&1; then
		candidate=$(command -v php)
	else
		fail "Unable to locate PHP ${DEPLOYER_PHP_VERSION} binary on the server"
	fi

	export DEPLOYER_PHP="$candidate"
}

# ----
# Deployment Functions
# ----

#
# Directory Management
# ----

#
# Prepare site directory structure
#
# Creates releases, shared, and repo directories if they don't exist.
# Cleans up any non-symlink current path.

prepare_directories() {
	echo "→ Preparing directories..."

	run_cmd mkdir -p "${SITE_ROOT}/releases" || fail "Failed to create releases directory"
	run_cmd mkdir -p "$SHARED_PATH" || fail "Failed to create shared directory"
	run_cmd mkdir -p "$REPO_PATH" || fail "Failed to create repo directory"

	# Ensure directories are owned by deployer
	run_cmd chown deployer:deployer "${SITE_ROOT}/releases" "$SHARED_PATH" "$REPO_PATH" || fail "Failed to set directory ownership"

	if [[ -e $CURRENT_PATH && ! -L $CURRENT_PATH ]]; then
		run_cmd rm -rf "$CURRENT_PATH" || fail "Failed to clean existing current path"
	fi
}

#
# Repository Management
# ----

#
# Ensure git host is in known_hosts
#
# Parses the repo domain and adds its host key if missing.
# Supports git@domain: and ssh://user@domain/ formats.

ensure_git_host_known() {
	local repo_domain=""

	if [[ $DEPLOYER_SITE_REPO =~ ^git@([^:]+): ]]; then
		repo_domain="${BASH_REMATCH[1]}"
	elif [[ $DEPLOYER_SITE_REPO =~ ^ssh://[^@]+@([^/]+)/ ]]; then
		repo_domain="${BASH_REMATCH[1]}"
	fi

	if [[ -z $repo_domain ]]; then
		return 0
	fi

	if [[ ! -d /home/deployer/.ssh ]]; then
		run_cmd mkdir -p /home/deployer/.ssh
		run_cmd chown deployer:deployer /home/deployer/.ssh
		run_cmd chmod 700 /home/deployer/.ssh
	fi

	if [[ ! -f /home/deployer/.ssh/known_hosts ]]; then
		run_as_deployer touch /home/deployer/.ssh/known_hosts
		run_cmd chmod 600 /home/deployer/.ssh/known_hosts
	fi

	if ! run_as_deployer ssh-keygen -F "$repo_domain" > /dev/null 2>&1; then
		echo "→ Adding host key for ${repo_domain}..."
		run_as_deployer ssh-keyscan -H "$repo_domain" >> /home/deployer/.ssh/known_hosts 2> /dev/null || true
	fi
}

#
# Clone or update git repository
#
# Clones the repository as a bare repo if it doesn't exist, otherwise fetches updates.
# Verifies the specified branch exists in the repository.

clone_or_update_repo() {
	ensure_git_host_known

	if [[ ! -d "${REPO_PATH}/objects" ]]; then
		echo "→ Cloning repository..."
		run_cmd rm -rf "$REPO_PATH" || true
		run_cmd mkdir -p "$(dirname "$REPO_PATH")" || fail "Failed to prepare repo parent"
		if ! run_as_deployer git clone --bare "$DEPLOYER_SITE_REPO" "$REPO_PATH"; then
			fail "Failed to clone repository"
		fi
	else
		echo "→ Fetching latest changes..."
		if ! run_as_deployer git --git-dir="$REPO_PATH" fetch --prune; then
			fail "Failed to fetch repository updates"
		fi
	fi

	if ! run_as_deployer git --git-dir="$REPO_PATH" rev-parse --verify "$DEPLOYER_SITE_BRANCH" > /dev/null 2>&1; then
		fail "Branch '${DEPLOYER_SITE_BRANCH}' not found in repository"
	fi
}

#
# Release Management
# ----

#
# Build new release from repository
#
# Creates timestamped release directory and exports code from the git repository.
# Sets proper ownership for deployer user.

build_release() {
	echo "→ Creating release ${RELEASE_NAME}..."
	run_cmd mkdir -p "$RELEASE_PATH" || fail "Failed to create release directory"
	run_cmd chown deployer:deployer "$RELEASE_PATH" || fail "Failed to set release ownership"

	local repo_q branch_q release_q
	printf -v repo_q "%q" "$REPO_PATH"
	printf -v branch_q "%q" "$DEPLOYER_SITE_BRANCH"
	printf -v release_q "%q" "$RELEASE_PATH"

	if ! run_as_deployer bash -c "set -euo pipefail; git --git-dir=${repo_q} archive ${branch_q} | tar -x -C ${release_q}"; then
		fail "Failed to export code for ${DEPLOYER_SITE_BRANCH}"
	fi

	# Ensure strict ownership and permissions on the release directory after extraction
	run_cmd chown -R deployer:deployer "$RELEASE_PATH" || fail "Failed to set release ownership"
	run_cmd chmod -R 755 "$RELEASE_PATH" || fail "Failed to set release permissions"
}

#
# Activate new release
#
# Updates the current symlink to point to the new release directory.

activate_release() {
	echo "→ Activating release..."

	run_as_deployer ln -sfn "$RELEASE_PATH" "$CURRENT_PATH" || fail "Failed to update current symlink"
}

#
# Remove old releases beyond retention limit
#
# Keeps only the specified number of most recent releases.

cleanup_releases() {
	local releases=()
	local total=0

	mapfile -t releases < <(find "${SITE_ROOT}/releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | sort) || true
	total=${#releases[@]}

	if ((total <= DEPLOYER_KEEP_RELEASES)); then
		return
	fi

	local remove_count=$((total - DEPLOYER_KEEP_RELEASES))
	for ((i = 0; i < remove_count; i++)); do
		local old_release="${SITE_ROOT}/releases/${releases[i]}"
		echo "→ Removing old release ${releases[i]}..."
		run_cmd rm -rf "$old_release" || fail "Failed to remove old release ${releases[i]}"
	done
}

#
# Reload PHP-FPM service
#
# Reloads the PHP-FPM service to apply changes (clears opcode cache etc)

reload_php_fpm() {
	echo "→ Reloading PHP-FPM..."
	run_cmd systemctl reload "php${DEPLOYER_PHP_VERSION}-fpm" || fail "Failed to reload PHP-FPM"
}

#
# Output Generation
# ----

#
# Write YAML output file with deployment results

write_output() {
	cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF
		status: success
		domain: ${DEPLOYER_SITE_DOMAIN}
		branch: ${DEPLOYER_SITE_BRANCH}
		release_name: ${RELEASE_NAME}
		release_path: ${RELEASE_PATH}
		current_path: ${CURRENT_PATH}
		keep_releases: ${DEPLOYER_KEEP_RELEASES}
	EOF
}

#
# Deployment Orchestration
# ----

#
# Execute full deployment hook sequence
#
# Runs hooks in order: build release -> link shared -> 1-building -> 2-releasing -> activate -> 3-finishing -> cleanup

run_hooks_sequence() {
	clone_or_update_repo

	build_release

	link_shared_resources

	run_hook '1-building.sh'
	run_hook '2-releasing.sh'

	activate_release

	run_hook '3-finishing.sh'

	reload_php_fpm

	cleanup_releases
}

# ----
# Main Execution
# ----

#
# Main entry point
#
# Orchestrates the complete deployment process

main() {
	detect_php_binary
	prepare_directories
	run_hooks_sequence
	write_output
}

main "$@"
