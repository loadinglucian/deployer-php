#!/usr/bin/env bash

#
# Site Delete
#
# Removes site directory, Nginx configuration, SSL certificates, cron entries,
# and supervisor programs, then reloads affected services.
#
# Output:
#   status: success
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

CONF_DIR="/etc/supervisor/conf.d"
CRON_LOG_DIR="/var/log/cron"
LOGROTATE_DIR="/etc/logrotate.d"

# ----
# Cron Cleanup Functions
# ----

#
# Remove cron entries for this domain from deployer's crontab

delete_cron_entries() {
	local domain=$1
	local start_marker="# DEPLOYER-CRON-START ${domain}"
	local end_marker="# DEPLOYER-CRON-END ${domain}"
	local current_crontab=""
	local updated_crontab=""

	# Get current crontab (ignore error if empty)
	current_crontab=$(run_cmd crontab -l -u deployer 2> /dev/null) || current_crontab=""

	if [[ -z $current_crontab ]]; then
		return 0
	fi

	# Check if domain has cron entries
	if ! echo "$current_crontab" | grep -q "$start_marker"; then
		return 0
	fi

	echo "→ Removing cron entries for ${domain}..."

	# Use awk to remove everything between markers (inclusive)
	updated_crontab=$(echo "$current_crontab" | awk -v start="$start_marker" -v end="$end_marker" '
		$0 == start { skip = 1; next }
		$0 == end { skip = 0; next }
		!skip { print }
	')

	# Remove trailing blank lines
	updated_crontab=$(echo "$updated_crontab" | sed -e :a -e '/^\s*$/d;N;ba' 2> /dev/null || echo "$updated_crontab")

	# Write updated crontab
	if [[ -n $updated_crontab ]]; then
		if ! echo "$updated_crontab" | run_cmd crontab -u deployer -; then
			echo "Error: Failed to update crontab" >&2
			exit 1
		fi
	else
		# Empty crontab - remove it entirely
		run_cmd crontab -r -u deployer 2> /dev/null || true
	fi
}

#
# Remove cron log files and logrotate configs for this domain

delete_cron_logs() {
	local domain=$1

	# Remove cron log files (including rotated: .log, .log.1, .log.*.gz)
	local log_files
	log_files=$(run_cmd find "$CRON_LOG_DIR" -maxdepth 1 \( -name "${domain}-*.log" -o -name "${domain}-*.log.[0-9]*" -o -name "${domain}-*.log.*.gz" \) -type f 2> /dev/null) || log_files=""

	if [[ -n $log_files ]]; then
		echo "→ Removing cron log files for ${domain}..."
		while IFS= read -r file_path; do
			[[ -z $file_path ]] && continue
			run_cmd rm -f "$file_path" || true
		done <<< "$log_files"
	fi

	# Remove cron logrotate configs
	local logrotate_files
	logrotate_files=$(run_cmd find "$LOGROTATE_DIR" -maxdepth 1 -name "cron-${domain}-*.conf" -type f 2> /dev/null) || logrotate_files=""

	if [[ -n $logrotate_files ]]; then
		echo "→ Removing cron logrotate configs for ${domain}..."
		while IFS= read -r file_path; do
			[[ -z $file_path ]] && continue
			run_cmd rm -f "$file_path" || true
		done <<< "$logrotate_files"
	fi
}

# ----
# Supervisor Cleanup Functions
# ----

#
# Stop and remove supervisor programs for this domain

delete_supervisor_programs() {
	local domain=$1

	# Find supervisor configs for this domain
	local config_files
	config_files=$(run_cmd find "$CONF_DIR" -maxdepth 1 -name "${domain}-*.conf" -type f 2> /dev/null) || config_files=""

	if [[ -z $config_files ]]; then
		return 0
	fi

	echo "→ Stopping supervisor programs for ${domain}..."

	# Stop each program before removing config
	while IFS= read -r file_path; do
		[[ -z $file_path ]] && continue

		local basename="${file_path##*/}"
		local program_name="${basename%.conf}"

		# Stop the program (ignore errors if not running)
		run_cmd supervisorctl stop "$program_name" 2> /dev/null || true

		echo "→ Removing supervisor config: ${basename}..."
		run_cmd rm -f "$file_path" || true
	done <<< "$config_files"

	# Reload supervisor to remove stopped programs
	echo "→ Reloading supervisor..."
	run_cmd supervisorctl reread > /dev/null 2>&1 || true
	run_cmd supervisorctl update > /dev/null 2>&1 || true
}

#
# Remove supervisor log files and logrotate configs for this domain

delete_supervisor_logs() {
	local domain=$1
	local supervisor_log_dir="/var/log/supervisor"

	# Remove supervisor log files (including rotated: .log, .log.1, .log.*.gz)
	local log_files
	log_files=$(run_cmd find "$supervisor_log_dir" -maxdepth 1 \( -name "${domain}-*.log" -o -name "${domain}-*.log.[0-9]*" -o -name "${domain}-*.log.*.gz" \) -type f 2> /dev/null) || log_files=""

	if [[ -n $log_files ]]; then
		echo "→ Removing supervisor log files for ${domain}..."
		while IFS= read -r file_path; do
			[[ -z $file_path ]] && continue
			run_cmd rm -f "$file_path" || true
		done <<< "$log_files"
	fi

	# Remove supervisor logrotate configs
	local logrotate_files
	logrotate_files=$(run_cmd find "$LOGROTATE_DIR" -maxdepth 1 -name "supervisor-${domain}-*.conf" -type f 2> /dev/null) || logrotate_files=""

	if [[ -n $logrotate_files ]]; then
		echo "→ Removing supervisor logrotate configs for ${domain}..."
		while IFS= read -r file_path; do
			[[ -z $file_path ]] && continue
			run_cmd rm -f "$file_path" || true
		done <<< "$logrotate_files"
	fi
}

# ----
# Nginx Cleanup Functions
# ----

#
# Delete Nginx virtual host configuration

delete_nginx_vhost() {
	local domain=$1
	local vhost_file="/etc/nginx/sites-available/${domain}"
	local enabled_link="/etc/nginx/sites-enabled/${domain}"

	# Remove enabled symlink first (disables the site)
	if run_cmd test -L "$enabled_link"; then
		echo "→ Disabling site ${domain}..."
		if ! run_cmd rm -f "$enabled_link"; then
			echo "Error: Failed to disable site" >&2
			exit 1
		fi
	fi

	# Remove vhost configuration file
	if run_cmd test -f "$vhost_file"; then
		echo "→ Deleting Nginx configuration for ${domain}..."
		if ! run_cmd rm -f "$vhost_file"; then
			echo "Error: Failed to delete Nginx configuration" >&2
			exit 1
		fi
	fi
}

#
# Delete SSL certificate and Certbot configuration

delete_ssl_certificate() {
	local domain=$1

	# Check if certificate exists (could be under domain or www.domain)
	local cert_exists=false

	if run_cmd test -d "/etc/letsencrypt/live/${domain}"; then
		cert_exists=true
		echo "→ Revoking SSL certificate for ${domain}..."
		# --non-interactive: Don't prompt for confirmation
		# --cert-name: Specify certificate by name
		run_cmd certbot delete --cert-name "$domain" --non-interactive 2> /dev/null || true
	fi

	if run_cmd test -d "/etc/letsencrypt/live/www.${domain}"; then
		cert_exists=true
		echo "→ Revoking SSL certificate for www.${domain}..."
		run_cmd certbot delete --cert-name "www.${domain}" --non-interactive 2> /dev/null || true
	fi

	if [[ "$cert_exists" == "false" ]]; then
		echo "→ No SSL certificate found for ${domain} (HTTPS may not have been enabled)"
	fi

	# Clean up any orphaned renewal configs (belt and suspenders)
	run_cmd rm -f "/etc/letsencrypt/renewal/${domain}.conf" 2> /dev/null || true
	run_cmd rm -f "/etc/letsencrypt/renewal/www.${domain}.conf" 2> /dev/null || true
}

#
# Reload Nginx to apply configuration changes

reload_nginx() {
	# Test configuration before reload
	if ! run_cmd nginx -t 2>&1; then
		echo "Warning: Nginx configuration test failed - manual intervention may be required" >&2
		return 1
	fi

	if systemctl is-active --quiet nginx 2> /dev/null; then
		echo "→ Reloading Nginx..."
		if ! run_cmd systemctl reload nginx; then
			echo "Warning: Failed to reload Nginx" >&2
			return 1
		fi
	fi

	return 0
}

# ----
# Site Cleanup Functions
# ----

#
# Delete site files

delete_site_files() {
	local domain=$1
	local site_path="/home/deployer/sites/${domain}"

	if run_cmd test -d "$site_path"; then
		echo "→ Deleting site files for ${domain}..."
		if ! run_cmd rm -rf "$site_path"; then
			echo "Error: Failed to delete site files" >&2
			exit 1
		fi
	fi
}

# ----
# Main Execution
# ----

main() {
	local domain=$DEPLOYER_SITE_DOMAIN

	# Clean up cron entries
	delete_cron_entries "$domain"
	delete_cron_logs "$domain"

	# Clean up supervisor programs
	delete_supervisor_programs "$domain"
	delete_supervisor_logs "$domain"

	# Clean up Nginx configuration
	delete_nginx_vhost "$domain"

	# Clean up SSL certificate and Certbot config
	delete_ssl_certificate "$domain"

	# Reload Nginx to apply changes
	reload_nginx

	# Delete site files (last, after all configs removed)
	delete_site_files "$domain"

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" << EOF; then
status: success
EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
