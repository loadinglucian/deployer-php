#!/usr/bin/env bash

set -euo pipefail

#
# Releasing - Runs immediately after building and right before the new release is activated
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

echo "→ Preparing release..."

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
# Laravel
# ----

if [[ $framework == "laravel" ]]; then

	#
	# Ensure shared data
	# ----

	echo "→ Ensuring shared data..."

	mkdir -p "${DEPLOYER_SHARED_PATH}/storage/"{app,framework,logs}
	mkdir -p "${DEPLOYER_SHARED_PATH}/storage/framework/"{cache,sessions,views}
	"${DEPLOYER_PHP}" artisan storage:link

	echo "→ Ensuring shared sqlite database..."
	mkdir -p "${DEPLOYER_SHARED_PATH}/database"

	shared_db="${DEPLOYER_SHARED_PATH}/database/database.sqlite"
	touch "${shared_db}"

	release_db="${DEPLOYER_RELEASE_PATH}/database/database.sqlite"
	if [ ! -e "${release_db}" ] && [ ! -L "${release_db}" ]; then
		ln -s "${shared_db}" "${release_db}"
	fi

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

# ----
# Symfony
# ----
# Uncomment as needed

# if [[ $framework == "symfony" ]]; then
# 	# Ensure shared data
# 	echo "→ Ensuring shared data..."
# 	mkdir -p "${DEPLOYER_SHARED_PATH}/var/"{cache,log,sessions}
#
# 	# Run migrations
# 	echo "→ Running migrations..."
# 	"${DEPLOYER_PHP}" bin/console doctrine:migrations:migrate --no-interaction
#
# 	# Code caching
# 	echo "→ Clearing cache..."
# 	"${DEPLOYER_PHP}" bin/console cache:clear
# fi

# ----
# CodeIgniter
# ----
# Uncomment as needed

# if [[ $framework == "codeigniter" ]]; then
# 	# Ensure shared data
# 	echo "→ Ensuring shared data..."
# 	mkdir -p "${DEPLOYER_SHARED_PATH}/writable/"{cache,logs,session,uploads}
#
# 	# Run migrations
# 	echo "→ Running migrations..."
# 	"${DEPLOYER_PHP}" spark migrate --all
#
# 	# Code caching
# 	echo "→ Optimizing..."
# 	"${DEPLOYER_PHP}" spark optimize
# fi
