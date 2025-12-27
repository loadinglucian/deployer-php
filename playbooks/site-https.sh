#!/usr/bin/env bash

#
# Site HTTPS
#
# Enables HTTPS for a site using Certbot with Let's Encrypt (HTTP-01 challenge).
# Certbot automatically modifies the Nginx configuration to add SSL and redirects.
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE  - Output file path
#   DEPLOYER_DISTRO       - Distribution: ubuntu|debian
#   DEPLOYER_PERMS        - Permissions: root|sudo|none
#   DEPLOYER_SITE_DOMAIN  - Domain name
#   DEPLOYER_WWW_MODE     - WWW mode: redirect-to-root|redirect-to-www
#
# Optional Environment Variables:
#   DEPLOYER_SSL_EMAIL    - Email for SSL certificate renewal notices (default: ssl@{domain})
#
# Output:
#   status: success
#   https_enabled: true
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_SITE_DOMAIN ]] && echo "Error: DEPLOYER_SITE_DOMAIN required" && exit 1
[[ -z $DEPLOYER_WWW_MODE ]] && echo "Error: DEPLOYER_WWW_MODE required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Certificate Functions
# ----

#
# Request SSL certificate from Let's Encrypt

request_certificate() {
	local domain=$1
	local www_mode=$DEPLOYER_WWW_MODE

	echo "→ Requesting SSL certificate from Let's Encrypt..."
	echo "→ Domain: ${domain}"
	echo "→ WWW Mode: ${www_mode}"

	# Determine domains to include in certificate based on WWW mode
	# Primary domain should be the one that will be served (not redirected)
	local domains=""
	case $www_mode in
		redirect-to-root)
			# Root domain is primary, www redirects to root
			domains="-d ${domain} -d www.${domain}"
			;;
		redirect-to-www)
			# WWW domain is primary, root redirects to www
			domains="-d www.${domain} -d ${domain}"
			;;
		*)
			echo "Error: Invalid WWW mode: ${www_mode}" >&2
			exit 1
			;;
	esac

	# Run Certbot with Nginx plugin
	# --nginx: Use Nginx plugin to configure SSL in Nginx config
	# --non-interactive: No prompts, fail if input required
	# --agree-tos: Agree to Let's Encrypt Terms of Service
	# --redirect: Configure HTTP to HTTPS redirect
	# --email: Email for urgent notices and recovery
	# --no-eff-email: Don't share email with EFF
	local ssl_email="${DEPLOYER_SSL_EMAIL:-ssl@${domain}}"

	if ! run_cmd certbot --nginx \
		--non-interactive \
		--agree-tos \
		--redirect \
		--email "$ssl_email" \
		--no-eff-email \
		$domains; then
		echo "Error: Failed to obtain SSL certificate from Let's Encrypt" >&2
		echo "Hint: Ensure DNS records point to this server and port 80 is accessible" >&2
		exit 1
	fi

	echo "→ SSL certificate obtained successfully"
}

#
# Verify certificate was installed correctly

verify_certificate() {
	local domain=$1

	echo "→ Verifying SSL certificate installation..."

	# Check if certificate files exist
	local cert_path="/etc/letsencrypt/live/${domain}/fullchain.pem"
	local key_path="/etc/letsencrypt/live/${domain}/privkey.pem"

	if ! run_cmd test -f "$cert_path"; then
		# Try with www prefix
		cert_path="/etc/letsencrypt/live/www.${domain}/fullchain.pem"
		key_path="/etc/letsencrypt/live/www.${domain}/privkey.pem"

		if ! run_cmd test -f "$cert_path"; then
			echo "Error: SSL certificate not found at expected paths" >&2
			exit 1
		fi
	fi

	# Verify certificate is valid and not expired
	if ! run_cmd openssl x509 -checkend 0 -noout -in "$cert_path" 2> /dev/null; then
		echo "Error: SSL certificate is expired or invalid" >&2
		exit 1
	fi

	# Get certificate expiry date for logging
	local expiry
	expiry=$(run_cmd openssl x509 -enddate -noout -in "$cert_path" 2> /dev/null | cut -d= -f2)
	echo "→ Certificate valid until: ${expiry}"
}

#
# Ensure auto-renewal is enabled

ensure_renewal() {
	echo "→ Ensuring certificate auto-renewal is configured..."

	# Enable Certbot timer for automatic renewals
	if ! systemctl is-enabled --quiet certbot.timer 2> /dev/null; then
		if ! run_cmd systemctl enable --quiet certbot.timer; then
			echo "Warning: Failed to enable certbot.timer for auto-renewal"
		fi
	fi

	if ! systemctl is-active --quiet certbot.timer 2> /dev/null; then
		if ! run_cmd systemctl start certbot.timer; then
			echo "Warning: Failed to start certbot.timer"
		fi
	fi

	echo "→ Auto-renewal configured via certbot.timer"
}

#
# Reload Nginx to apply changes

reload_nginx() {
	echo "→ Testing Nginx configuration..."
	if ! run_cmd nginx -t 2>&1; then
		echo "Error: Nginx configuration test failed after SSL setup" >&2
		exit 1
	fi

	echo "→ Reloading Nginx..."
	if ! run_cmd systemctl reload nginx; then
		echo "Error: Failed to reload Nginx" >&2
		exit 1
	fi
}

# ----
# Main Execution
# ----

main() {
	local domain=$DEPLOYER_SITE_DOMAIN
	local vhost_file="/etc/nginx/sites-available/${domain}"

	# Verify Nginx vhost exists
	if ! run_cmd test -f "$vhost_file"; then
		echo "Error: Site configuration file not found: ${vhost_file}" >&2
		echo "Hint: Run site:create first to create the site" >&2
		exit 1
	fi

	# Request and configure SSL certificate
	request_certificate "$domain"
	verify_certificate "$domain"
	ensure_renewal
	reload_nginx

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
		https_enabled: true
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
