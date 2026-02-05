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

	echo "→ Linking shared storage..."
	rm -rf "${DEPLOYER_RELEASE_PATH}/storage"
	ln -sfn "${DEPLOYER_SHARED_PATH}/storage" "${DEPLOYER_RELEASE_PATH}/storage"

	echo "→ Ensuring shared sqlite database..."
	mkdir -p "${DEPLOYER_SHARED_PATH}/database"
	touch "${DEPLOYER_SHARED_PATH}/database/database.sqlite"

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
	"${DEPLOYER_PHP}" artisan optimize

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
