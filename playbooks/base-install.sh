#!/usr/bin/env bash

#
# Base Installation
#
# Installs Nginx, Certbot, Git, and core server packages for deployment infrastructure.
# Configures UFW firewall with secure defaults (SSH, 80, 443 only).
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE  - Output file path
#   DEPLOYER_DISTRO       - Distribution: ubuntu|debian
#   DEPLOYER_PERMS        - Permissions: root|sudo|none
#   DEPLOYER_SSH_PORT     - SSH port to allow through firewall
#
# Output:
#   status: success
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SSH_PORT ]] && echo "Error: DEPLOYER_SSH_PORT required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Installation Functions
# ----

#
# Install packages
# ----

install_packages() {
	echo "→ Installing packages..."

	local common_packages=(curl zip unzip nginx certbot python3-certbot-nginx git rsync ufw jq supervisor)
	local distro_packages

	case $DEPLOYER_DISTRO in
		ubuntu)
			distro_packages=(software-properties-common)
			if ! apt_get_with_retry install -y "${common_packages[@]}" "${distro_packages[@]}"; then
				echo "Error: Failed to install packages" >&2
				exit 1
			fi
			;;
		debian)
			distro_packages=(apt-transport-https lsb-release ca-certificates)
			if ! apt_get_with_retry install -y "${common_packages[@]}" "${distro_packages[@]}"; then
				echo "Error: Failed to install packages" >&2
				exit 1
			fi
			;;
	esac
}

#
# Base Nginx config
# ----

config_nginx() {
	echo "→ Setting up Nginx base config..."

	# Create directory structure (sites-available/sites-enabled pattern)
	if ! run_cmd mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled; then
		echo "Error: Failed to create Nginx config directories" >&2
		exit 1
	fi

	# Create log directory with correct ownership
	if [[ ! -d /var/log/nginx ]]; then
		if ! run_cmd mkdir -p /var/log/nginx; then
			echo "Error: Failed to create Nginx log directory" >&2
			exit 1
		fi
	fi

	if ! run_cmd chown -R www-data:www-data /var/log/nginx; then
		echo "Error: Failed to set ownership on Nginx log directory" >&2
		exit 1
	fi

	# Check if our custom config is already in place
	if ! grep -q "include /etc/nginx/sites-enabled" /etc/nginx/nginx.conf 2> /dev/null; then
		echo "→ Creating optimized nginx.conf..."

		# Backup original
		run_cmd cp /etc/nginx/nginx.conf /etc/nginx/nginx.conf.original

		if ! run_cmd tee /etc/nginx/nginx.conf > /dev/null <<- 'EOF'; then
			user www-data;
			worker_processes auto;
			pid /run/nginx.pid;
			include /etc/nginx/modules-enabled/*.conf;

			events {
			    worker_connections 1024;
			    multi_accept on;
			    use epoll;
			}

			http {
			    # Basic Settings
			    sendfile on;
			    tcp_nopush on;
			    tcp_nodelay on;
			    keepalive_timeout 65;
			    types_hash_max_size 2048;
			    server_tokens off;

			    # MIME Types
			    include /etc/nginx/mime.types;
			    default_type application/octet-stream;

			    # Logging
			    log_format main '$remote_addr - $remote_user [$time_local] "$request" '
			                    '$status $body_bytes_sent "$http_referer" '
			                    '"$http_user_agent" "$http_x_forwarded_for"';

			    access_log /var/log/nginx/access.log main;
			    error_log /var/log/nginx/error.log;

			    # Gzip Compression
			    gzip on;
			    gzip_vary on;
			    gzip_proxied any;
			    gzip_comp_level 6;
			    gzip_min_length 256;
			    gzip_types
			        application/atom+xml
			        application/javascript
			        application/json
			        application/ld+json
			        application/manifest+json
			        application/rss+xml
			        application/vnd.geo+json
			        application/vnd.ms-fontobject
			        application/x-font-ttf
			        application/x-web-app-manifest+json
			        application/xhtml+xml
			        application/xml
			        font/opentype
			        image/bmp
			        image/svg+xml
			        image/x-icon
			        text/cache-manifest
			        text/css
			        text/plain
			        text/vcard
			        text/vnd.rim.location.xloc
			        text/vtt
			        text/x-component
			        text/x-cross-domain-policy
			        text/xml;

			    # Client Body Settings
			    client_max_body_size 100M;
			    client_body_buffer_size 128k;

			    # Include Virtual Host Configs
			    include /etc/nginx/sites-enabled/*;
			}
		EOF
			echo "Error: Failed to create nginx.conf" >&2
			exit 1
		fi
	fi

	# Create stub_status for monitoring (localhost only, port 8080)
	if ! run_cmd test -f /etc/nginx/sites-available/stub_status; then
		echo "→ Creating monitoring endpoint..."
		if ! run_cmd tee /etc/nginx/sites-available/stub_status > /dev/null <<- 'EOF'; then
			# Nginx monitoring - localhost only (not accessible from internet)
			server {
			    listen 127.0.0.1:8080;
			    server_name localhost;

			    # Nginx status endpoint
			    location /nginx_status {
			        stub_status on;
			        access_log off;
			        allow 127.0.0.1;
			        deny all;
			    }

			    #### DEPLOYER-PHP CONFIG ####
			}
		EOF
			echo "Error: Failed to create stub_status config" >&2
			exit 1
		fi

		if ! run_cmd ln -sf /etc/nginx/sites-available/stub_status /etc/nginx/sites-enabled/stub_status; then
			echo "Error: Failed to enable stub_status" >&2
			exit 1
		fi
	fi

	# Remove default site if it exists
	run_cmd rm -f /etc/nginx/sites-enabled/default 2> /dev/null || true

	# Test configuration
	if ! run_cmd nginx -t 2>&1; then
		echo "Error: Nginx configuration test failed" >&2
		exit 1
	fi

	# Start or reload Nginx
	if systemctl is-active --quiet nginx 2> /dev/null; then
		echo "→ Reloading Nginx configuration..."
		if ! run_cmd systemctl reload nginx 2> /dev/null; then
			echo "Warning: Failed to reload Nginx configuration"
		fi
	else
		echo "→ Starting Nginx..."
		if ! run_cmd systemctl start nginx; then
			echo "Error: Failed to start Nginx" >&2
			exit 1
		fi
	fi

	# Enable Nginx service
	if ! systemctl is-enabled --quiet nginx 2> /dev/null; then
		run_cmd systemctl enable --quiet nginx
	fi

	# Enable Certbot auto-renewal timer
	if ! systemctl is-enabled --quiet certbot.timer 2> /dev/null; then
		run_cmd systemctl enable --quiet certbot.timer
	fi
	if ! systemctl is-active --quiet certbot.timer 2> /dev/null; then
		run_cmd systemctl start certbot.timer
	fi
}

#
# Configure UFW firewall with secure defaults
# ----

config_ufw() {
	echo "→ Configuring firewall..."

	# Detect actual sshd listening port (handles port-forwarding scenarios)
	local detected_port
	detected_port=$(detect_sshd_port)

	# SSH Safety: Allow SSH before any changes (prevents lockout if UFW is active)
	run_cmd ufw allow "${detected_port}/tcp" > /dev/null 2>&1 || true
	if [[ $DEPLOYER_SSH_PORT -ne $detected_port ]]; then
		run_cmd ufw allow "$DEPLOYER_SSH_PORT/tcp" > /dev/null 2>&1 || true
	fi

	# Reset UFW to clear any existing rules
	run_cmd ufw --force reset > /dev/null 2>&1 || fail "Failed to reset UFW"

	# Re-allow SSH immediately after reset
	run_cmd ufw allow "${detected_port}/tcp" > /dev/null 2>&1 || fail "Failed to allow SSH port ${detected_port}"
	if [[ $DEPLOYER_SSH_PORT -ne $detected_port ]]; then
		run_cmd ufw allow "$DEPLOYER_SSH_PORT/tcp" > /dev/null 2>&1 || fail "Failed to allow SSH port ${DEPLOYER_SSH_PORT}"
	fi

	# Set default policies
	run_cmd ufw default deny incoming > /dev/null 2>&1 || fail "Failed to set incoming policy"
	run_cmd ufw default allow outgoing > /dev/null 2>&1 || fail "Failed to set outgoing policy"

	# Allow HTTP/HTTPS
	run_cmd ufw allow 80/tcp > /dev/null 2>&1 || fail "Failed to allow port 80"
	run_cmd ufw allow 443/tcp > /dev/null 2>&1 || fail "Failed to allow port 443"

	# Enable UFW
	run_cmd ufw --force enable > /dev/null 2>&1 || fail "Failed to enable UFW"
}

# ----
# Main Execution
# ----

main() {
	# Execute installation tasks
	install_packages
	config_nginx
	config_ufw

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
