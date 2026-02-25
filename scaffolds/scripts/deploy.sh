#!/usr/bin/env bash

set -euo pipefail

#
# Deploy Script - Link shared resources, build, and prepare for release activation
# ----
#
# Environment variables provided by Deployer PHP:
#   DEPLOYER_RELEASE_PATH  - Absolute path to the new release directory
#   DEPLOYER_SHARED_PATH   - Absolute path to the shared/ directory
#   DEPLOYER_CURRENT_PATH  - Absolute path to the current/ symlink
#   DEPLOYER_REPO_PATH     - Path to the git cache (bare) repository
#   DEPLOYER_DOMAIN        - Site domain (example.com)
#   DEPLOYER_BRANCH        - Git branch being deployed
#   DEPLOYER_PHP_VERSION   - PHP version selected for this site (e.g. 8.4)
#   DEPLOYER_PHP           - Absolute path to the PHP binary (e.g. /usr/bin/php8.4)
#
# You're automatically in the DEPLOYER_RELEASE_PATH directory at this point:

#
# Permissions helper
# ----
#
# Code runs with PHP-FPM as `www-data` while deploy commands run as
# `deployer`. In DeployerPHP setups, `www-data` is added to the
# `deployer` group so both can write to shared runtime paths.
#
# This helper keeps shared writable trees consistent:
# - Directories: `2775` (`rwxrwxr-x` + SGID bit)
# - Files: `664` (`rw-rw-r--`)
#
# The leading `2` in `2775` enables SGID on directories, so newly created
# files/directories inherit the parent directory group instead of the creator's
# primary group.

set_group_writable_tree() {
	local path="$1"
	local current_user

	if [[ ! -d "${path}" ]]; then
		return
	fi

	current_user="$(id -un)"

	# chmod can only be performed by file owner (or root). During runtime some
	# cache files may be owned by www-data, so normalize only deployer-owned
	# entries and do not fail deployment if skipped files exist.
	find "${path}" -type d -user "${current_user}" -exec chmod 2775 {} + 2> /dev/null || true
	find "${path}" -type f -user "${current_user}" -exec chmod 664 {} + 2> /dev/null || true
}

# ----
# Framework Detection
# ----

framework=""

if [[ -f artisan ]]; then
	framework="laravel"
elif [[ -f bin/console ]]; then
	framework="symfony"
elif [[ -f spark ]]; then
	framework="codeigniter"
fi

# ----
# Shared Resources
# ----

# Laravel
if [[ $framework == "laravel" ]]; then
	echo "→ Ensuring shared storage directories..."
	mkdir -p "${DEPLOYER_SHARED_PATH}/storage/"{app,framework,logs}
	mkdir -p "${DEPLOYER_SHARED_PATH}/storage/framework/"{cache,sessions,views}

	# Make shared storage writable for both deploy user and PHP-FPM group member:
	set_group_writable_tree "${DEPLOYER_SHARED_PATH}/storage"

	echo "→ Linking shared storage..."
	rm -rf "${DEPLOYER_RELEASE_PATH}/storage"
	ln -sfn "${DEPLOYER_SHARED_PATH}/storage" "${DEPLOYER_RELEASE_PATH}/storage"

	echo "→ Ensuring shared sqlite database..."
	mkdir -p "${DEPLOYER_SHARED_PATH}/database"
	touch "${DEPLOYER_SHARED_PATH}/database/database.sqlite"

	# Keep sqlite path writable for both deploy user and PHP-FPM group member:
	chmod 2775 "${DEPLOYER_SHARED_PATH}/database"
	chmod 664 "${DEPLOYER_SHARED_PATH}/database/database.sqlite"

	echo "→ Linking shared database.sqlite..."
	ln -sf "${DEPLOYER_SHARED_PATH}/database/database.sqlite" "${DEPLOYER_RELEASE_PATH}/database/database.sqlite"
fi

# Symfony (uncomment as needed)
# if [[ $framework == "symfony" ]]; then
# 	echo "→ Ensuring shared var directories..."
# 	mkdir -p "${DEPLOYER_SHARED_PATH}/var/"{log,sessions}
#
# 	echo "→ Linking shared var..."
# 	rm -rf "${DEPLOYER_RELEASE_PATH}/var"
# 	ln -sfn "${DEPLOYER_SHARED_PATH}/var" "${DEPLOYER_RELEASE_PATH}/var"
# fi

# CodeIgniter (uncomment as needed)
# if [[ $framework == "codeigniter" ]]; then
# 	echo "→ Ensuring shared writable directories..."
# 	mkdir -p "${DEPLOYER_SHARED_PATH}/writable/"{cache,logs,session,uploads}
#
# 	echo "→ Linking shared writable..."
# 	rm -rf "${DEPLOYER_RELEASE_PATH}/writable"
# 	ln -sfn "${DEPLOYER_SHARED_PATH}/writable" "${DEPLOYER_RELEASE_PATH}/writable"
# fi

# .env (framework-agnostic)
if [[ -f "${DEPLOYER_SHARED_PATH}/.env" ]]; then
	echo "→ Linking shared .env..."
	ln -sf "${DEPLOYER_SHARED_PATH}/.env" "${DEPLOYER_RELEASE_PATH}/.env"
fi

# ----
# Build
# ----

echo "→ Building release..."

if [[ -f composer.json ]]; then
	echo "→ Installing Composer dependencies..."

	composer_bin="$(command -v composer || true)"
	"${DEPLOYER_PHP}" "${composer_bin}" install --no-interaction --no-dev --optimize-autoloader
fi

if [[ -f package.json ]]; then
	echo "→ Installing frontend dependencies..."
	bun install --frozen-lockfile

	echo "→ Building frontend assets..."
	bun run build
fi

# ----
# Release Preparation
# ----

# Laravel
if [[ $framework == "laravel" ]]; then

	echo "→ Creating storage symlink..."
	"${DEPLOYER_PHP}" artisan storage:link

	echo "→ Ensuring app key exists..."
	"${DEPLOYER_PHP}" artisan key:generate || true

	#
	# Run migrations
	# ----

	echo "→ Running migrations..."
	"${DEPLOYER_PHP}" artisan migrate --force

	#
	# Code caching
	# ----

	echo "→ Optimizing..."
	"${DEPLOYER_PHP}" artisan optimize:clear
	"${DEPLOYER_PHP}" artisan optimize

	# Artisan commands can create new cache/session/log paths. Re-apply permissions
	# to keep shared storage writable for both deploy user and PHP-FPM group member:
	set_group_writable_tree "${DEPLOYER_SHARED_PATH}/storage"

fi

# Symfony (uncomment as needed)
# if [[ $framework == "symfony" ]]; then
# 	# Run migrations
# 	echo "→ Running migrations..."
# 	"${DEPLOYER_PHP}" bin/console doctrine:migrations:migrate --no-interaction
#
# 	# Code caching
# 	echo "→ Clearing cache..."
# 	"${DEPLOYER_PHP}" bin/console cache:clear
# fi

# CodeIgniter (uncomment as needed)
# if [[ $framework == "codeigniter" ]]; then
# 	# Run migrations
# 	echo "→ Running migrations..."
# 	"${DEPLOYER_PHP}" spark migrate --all
#
# 	# Code caching
# 	echo "→ Optimizing..."
# 	"${DEPLOYER_PHP}" spark optimize
# fi
