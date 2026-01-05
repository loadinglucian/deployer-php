#!/usr/bin/env bash

#
# Site Create
#
# Creates site directory structure and Nginx configuration for a new domain.
#
# Output:
#   status: success
#   site_path: /home/deployer/sites/example.com
#   site_configured: true
#   php_version: "8.4"
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
[[ -z $DEPLOYER_PHP_VERSION ]] && echo "Error: DEPLOYER_PHP_VERSION required" && exit 1
[[ -z $DEPLOYER_WWW_MODE ]] && echo "Error: DEPLOYER_WWW_MODE required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Setup Functions
# ----

#
# Directory Structure Setup
# ----

#
# Create atomic deployment directory structure for site

setup_site_directories() {
	local domain=$1
	local site_path="/home/deployer/sites/${domain}"

	echo "→ Creating directory structure..."

	# Create main site directory
	if ! run_cmd test -d "$site_path"; then
		if ! run_cmd mkdir -p "$site_path"; then
			echo "Error: Failed to create directory: ${site_path}" >&2
			exit 1
		fi
	fi

	# Create atomic deployment structure
	local dirs=(
		"${site_path}/releases"
		"${site_path}/shared"
		"${site_path}/repo"
		"${site_path}/current/public"
	)

	for dir in "${dirs[@]}"; do
		if ! run_cmd test -d "$dir"; then
			if ! run_cmd mkdir -p "$dir"; then
				echo "Error: Failed to create directory: ${dir}" >&2
				exit 1
			fi
		fi
	done

	# Set ownership
	if ! run_cmd chown -R deployer:deployer "$site_path"; then
		echo "Error: Failed to set ownership on site directory" >&2
		exit 1
	fi

	# Set permissions on all directories (755 - owner rwx, group+others rx)
	# This ensures git and other tools can traverse and execute properly
	if ! run_cmd find "$site_path" -type d -exec chmod 755 {} +; then
		echo "Error: Failed to set directory permissions" >&2
		exit 1
	fi
}

#
# Default Page Setup
# ----

#
# Create default placeholder page for site

setup_default_page() {
	local domain=$1
	local index_file="/home/deployer/sites/${domain}/current/public/index.php"

	echo "→ Creating default page..."

	if ! run_cmd test -f "$index_file"; then
		if ! run_cmd tee "$index_file" > /dev/null <<- 'EOF'; then
			<?php
			echo '<ul>';
			echo '<li>Run <strong>site:https</strong> to enable HTTPS</li>';
			echo '<li>Deploy your new site with <strong>site:deploy</strong></li>';
			echo '</ul>';
		EOF
			echo "Error: Failed to create index.php" >&2
			exit 1
		fi
	fi

	# Set file permissions (640 - owner+group read)
	if ! run_cmd chmod 640 "$index_file"; then
		echo "Error: Failed to set permissions on index.php" >&2
		exit 1
	fi

	# Set ownership
	if ! run_cmd chown deployer:deployer "$index_file"; then
		echo "Error: Failed to set ownership on index.php" >&2
		exit 1
	fi
}

#
# Nginx Configuration
# ----

#
# Configure Nginx virtual host for site

configure_nginx_vhost() {
	local domain=$1
	local site_path="/home/deployer/sites/${domain}"
	local www_mode=$DEPLOYER_WWW_MODE
	local php_version=$DEPLOYER_PHP_VERSION
	local php_fpm_socket="/run/php/php${php_version}-fpm.sock"
	local vhost_file="/etc/nginx/sites-available/${domain}"

	echo "→ Creating Nginx configuration for ${domain}..."
	echo "→ Using PHP ${php_version} (socket: ${php_fpm_socket})"
	echo "→ WWW mode: ${www_mode}"

	# Ensure log directory exists with correct ownership
	if [[ ! -d /var/log/nginx ]]; then
		if ! run_cmd mkdir -p /var/log/nginx; then
			echo "Error: Failed to create Nginx log directory" >&2
			exit 1
		fi
	fi
	run_cmd chown -R www-data:www-data /var/log/nginx

	# Common server block configuration (shared between www/non-www)
	local server_block="
    root ${site_path}/current/public;
    index index.php index.html index.htm;

    # Logging
    access_log /var/log/nginx/${domain}-access.log;
    error_log /var/log/nginx/${domain}-error.log;

    # Security headers
    add_header X-Frame-Options \"SAMEORIGIN\" always;
    add_header X-Content-Type-Options \"nosniff\" always;
    add_header X-XSS-Protection \"1; mode=block\" always;

    # Handle requests
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # PHP handling
    location ~ \\.php\$ {
        try_files \$uri =404;
        fastcgi_split_path_info ^(.+\\.php)(/.+)\$;
        fastcgi_pass unix:${php_fpm_socket};
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }

    # Deny access to hidden files (except .well-known for ACME challenges)
    location ~ /\\.(?!well-known).* {
        deny all;
    }

    # Deny access to sensitive files
    location ~* (?:^|/)\\.env\$ {
        deny all;
    }

    # Static file caching
    location ~* \\.(?:css|js|jpg|jpeg|gif|png|ico|cur|gz|svg|svgz|mp4|ogg|ogv|webm|htc|woff|woff2)\$ {
        expires 1M;
        access_log off;
        add_header Cache-Control \"public\";
    }
"

	# Build configuration based on WWW mode
	local nginx_config=""

	case $www_mode in
		redirect-to-root)
			# Redirect www to non-www (HTTP only - Certbot adds HTTPS later)
			nginx_config="# Site: ${domain} (Redirect www -> root)
# Generated by DeployerPHP

server {
    listen 80;
    listen [::]:80;
    server_name www.${domain};
    return 301 http://${domain}\$request_uri;
}

server {
    listen 80;
    listen [::]:80;
    server_name ${domain};
${server_block}
}"
			;;
		redirect-to-www)
			# Redirect non-www to www (HTTP only - Certbot adds HTTPS later)
			nginx_config="# Site: ${domain} (Redirect root -> www)
# Generated by DeployerPHP

server {
    listen 80;
    listen [::]:80;
    server_name ${domain};
    return 301 http://www.${domain}\$request_uri;
}

server {
    listen 80;
    listen [::]:80;
    server_name www.${domain};
${server_block}
}"
			;;
		*)
			echo "Error: Invalid WWW mode: ${www_mode}. Expected: redirect-to-root or redirect-to-www" >&2
			exit 1
			;;
	esac

	# Write vhost configuration
	if ! echo -e "$nginx_config" | run_cmd tee "$vhost_file" > /dev/null; then
		echo "Error: Failed to create Nginx configuration at ${vhost_file}" >&2
		exit 1
	fi

	# Enable site by creating symlink
	if ! run_cmd ln -sf "$vhost_file" "/etc/nginx/sites-enabled/${domain}"; then
		echo "Error: Failed to enable site (symlink creation failed)" >&2
		exit 1
	fi

	echo "→ Nginx configuration created: ${vhost_file}"
}

#
# Service Management
# ----

#
# Reload Nginx and restart PHP-FPM

reload_services() {
	local php_version=$DEPLOYER_PHP_VERSION

	echo "→ Verifying and reloading services..."

	# Test Nginx configuration before reload
	if ! run_cmd nginx -t 2>&1; then
		echo "Error: Nginx configuration test failed" >&2
		exit 1
	fi

	# Enable Nginx if not already enabled
	if ! systemctl is-enabled --quiet nginx 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet nginx; then
			echo "Error: Failed to enable Nginx service" >&2
			exit 1
		fi
	fi

	# Reload or start Nginx
	if systemctl is-active --quiet nginx 2> /dev/null; then
		echo "→ Reloading Nginx..."
		if ! run_cmd systemctl reload nginx; then
			echo "Error: Failed to reload Nginx" >&2
			exit 1
		fi
	else
		echo "→ Starting Nginx..."
		if ! run_cmd systemctl start nginx; then
			echo "Error: Failed to start Nginx" >&2
			exit 1
		fi
	fi

	# Restart PHP-FPM to ensure it recognizes the new site
	if systemctl is-active --quiet "php${php_version}-fpm" 2> /dev/null; then
		echo "→ Restarting PHP-FPM ${php_version}..."
		if ! run_cmd systemctl restart "php${php_version}-fpm"; then
			echo "Warning: Failed to restart PHP-FPM service"
		fi
	fi
}

# ----
# Main Execution
# ----

main() {
	local domain=$DEPLOYER_SITE_DOMAIN
	local site_path="/home/deployer/sites/${domain}"

	# Execute setup tasks
	setup_site_directories "$domain"
	setup_default_page "$domain"
	configure_nginx_vhost "$domain"
	reload_services

	# Use specified PHP version for output
	local php_version=$DEPLOYER_PHP_VERSION

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		site_path: ${site_path}
		site_configured: true
		php_version: ${php_version}
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
