#!/usr/bin/env bash
#
# Server Optimization Playbook - Ubuntu/Debian Only
#
# Optimize PHP-FPM and OPcache based on server hardware
# ----
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE - Output file path
#   DEPLOYER_PERMS       - Permissions: root|sudo
#   DEPLOYER_CPU_CORES   - Number of CPU cores
#   DEPLOYER_RAM_MB      - Total RAM in MB
#   DEPLOYER_DISK_TYPE   - Disk type: ssd|hdd
#
# Returns YAML with:
#   - status: success
#   - php_fpm_settings: applied settings
#   - opcache_settings: applied settings
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
[[ -z $DEPLOYER_CPU_CORES ]] && echo "Error: DEPLOYER_CPU_CORES required" && exit 1
[[ -z $DEPLOYER_RAM_MB ]] && echo "Error: DEPLOYER_RAM_MB required" && exit 1
[[ -z $DEPLOYER_DISK_TYPE ]] && echo "Error: DEPLOYER_DISK_TYPE required" && exit 1
export DEPLOYER_PERMS

#
# Helper Functions
# ----

run_cmd() {
	if [[ $DEPLOYER_PERMS == 'root' ]]; then
		"$@"
	else
		sudo -n "$@"
	fi
}

#
# Calculation Functions
# ----

#
# Calculate optimal PHP-FPM process manager settings based on RAM

calculate_php_fpm_settings() {
	local ram_mb=$1
	local avg_process_mb=40
	local max_children=$(((ram_mb * 75 / 100) / avg_process_mb))

	# Clamp to reasonable bounds
	[[ $max_children -lt 5 ]] && max_children=5
	[[ $max_children -gt 200 ]] && max_children=200

	if ((ram_mb < 1024)); then
		echo "ondemand|$max_children|10s"
	elif ((ram_mb >= 8192)); then
		local start_servers=$((max_children * 60 / 100))
		echo "static|$max_children|$start_servers"
	else
		local start_servers=$((max_children * 25 / 100))
		local min_spare=$((max_children * 15 / 100))
		local max_spare=$((max_children * 35 / 100))
		echo "dynamic|$max_children|$start_servers|$min_spare|$max_spare"
	fi
}

#
# Calculate OPcache memory settings based on hardware

calculate_opcache_settings() {
	local ram_mb=$1
	local cpu_cores=$2
	local opcache_memory jit_buffer interned_strings

	if ((ram_mb < 1024)); then
		opcache_memory=64
		interned_strings=8
		jit_buffer=32
	elif ((ram_mb < 2048)); then
		opcache_memory=128
		interned_strings=8
		jit_buffer=64
	elif ((ram_mb < 4096)); then
		opcache_memory=256
		interned_strings=16
		jit_buffer=64
	else
		opcache_memory=512
		interned_strings=16
		jit_buffer=128
	fi

	echo "$opcache_memory|$jit_buffer|$interned_strings"
}

#
# Configuration Functions
# ----

#
# Apply PHP-FPM process manager optimization

configure_php_fpm_pool() {
	local ram_mb=$1
	local pool_conf="/etc/php/8.4/fpm/pool.d/www.conf"

	if ! run_cmd test -f "$pool_conf"; then
		echo "Error: PHP-FPM pool config not found: $pool_conf" >&2
		exit 1
	fi

	echo "✓ Configuring PHP-FPM process manager..."

	local settings pm max_children param3 param4 param5
	IFS='|' read -r pm max_children param3 param4 param5 <<< "$(calculate_php_fpm_settings "$ram_mb")"

	# Apply process manager settings
	if ! run_cmd sed -i "s/^pm = .*/pm = $pm/" "$pool_conf"; then
		echo "Error: Failed to set pm mode" >&2
		exit 1
	fi

	if ! run_cmd sed -i "s/^pm.max_children = .*/pm.max_children = $max_children/" "$pool_conf"; then
		echo "Error: Failed to set pm.max_children" >&2
		exit 1
	fi

	if [[ $pm == "ondemand" ]]; then
		if ! run_cmd sed -i "s/^;*pm.process_idle_timeout = .*/pm.process_idle_timeout = $param3/" "$pool_conf"; then
			echo "Error: Failed to set pm.process_idle_timeout" >&2
			exit 1
		fi
	elif [[ $pm == "static" ]]; then
		if ! run_cmd sed -i "s/^;*pm.start_servers = .*/pm.start_servers = $param3/" "$pool_conf"; then
			echo "Error: Failed to set pm.start_servers" >&2
			exit 1
		fi
	else # dynamic
		if ! run_cmd sed -i "s/^pm.start_servers = .*/pm.start_servers = $param3/" "$pool_conf"; then
			echo "Error: Failed to set pm.start_servers" >&2
			exit 1
		fi
		if ! run_cmd sed -i "s/^pm.min_spare_servers = .*/pm.min_spare_servers = $param4/" "$pool_conf"; then
			echo "Error: Failed to set pm.min_spare_servers" >&2
			exit 1
		fi
		if ! run_cmd sed -i "s/^pm.max_spare_servers = .*/pm.max_spare_servers = $param5/" "$pool_conf"; then
			echo "Error: Failed to set pm.max_spare_servers" >&2
			exit 1
		fi
	fi

	if ! run_cmd sed -i "s/^;*pm.max_requests = .*/pm.max_requests = 1000/" "$pool_conf"; then
		echo "Error: Failed to set pm.max_requests" >&2
		exit 1
	fi

	echo "pm=$pm|max_children=$max_children"
}

#
# Create optimized OPcache configuration

configure_opcache() {
	local ram_mb=$1 cpu_cores=$2 disk_type=$3
	local opcache_memory jit_buffer interned_strings

	IFS='|' read -r opcache_memory jit_buffer interned_strings <<< "$(calculate_opcache_settings "$ram_mb" "$cpu_cores")"

	echo "✓ Configuring OPcache..."

	if ! cat > /tmp/99-deployer-opcache.ini << EOF; then
; Deployer PHP - OPcache Performance Configuration
; Auto-generated based on server hardware: ${ram_mb}MB RAM, ${cpu_cores} CPU cores, ${disk_type} disk

[opcache]
; Enable OPcache
opcache.enable=1
opcache.enable_cli=1

; Memory settings
opcache.memory_consumption=${opcache_memory}
opcache.interned_strings_buffer=${interned_strings}

; File cache settings
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
opcache.fast_shutdown=1

; JIT compilation (PHP 8.0+)
opcache.jit_buffer_size=${jit_buffer}M
opcache.jit=tracing
EOF
		echo "Error: Failed to create OPcache config" >&2
		exit 1
	fi

	# Add SSD-specific optimizations
	if [[ $disk_type == "ssd" ]]; then
		if ! cat >> /tmp/99-deployer-opcache.ini << EOF; then

; SSD optimizations
opcache.file_cache=/var/cache/php/opcache
opcache.file_cache_only=0
EOF
			echo "Error: Failed to add SSD optimizations" >&2
			exit 1
		fi

		if ! run_cmd mkdir -p /var/cache/php/opcache; then
			echo "Error: Failed to create OPcache directory" >&2
			exit 1
		fi

		if ! run_cmd chown www-data:www-data /var/cache/php/opcache; then
			echo "Error: Failed to set OPcache directory ownership" >&2
			exit 1
		fi

		if ! run_cmd chmod 755 /var/cache/php/opcache; then
			echo "Error: Failed to set OPcache directory permissions" >&2
			exit 1
		fi
	fi

	if ! run_cmd mv /tmp/99-deployer-opcache.ini /etc/php/8.4/fpm/conf.d/99-deployer-opcache.ini; then
		echo "Error: Failed to install OPcache config" >&2
		exit 1
	fi

	echo "memory=${opcache_memory}M|jit=${jit_buffer}M|strings=${interned_strings}M"
}

#
# Main Execution
# ----

main() {
	local php_fpm_result opcache_result

	# Apply optimizations
	php_fpm_result=$(configure_php_fpm_pool "$DEPLOYER_RAM_MB")
	opcache_result=$(configure_opcache "$DEPLOYER_RAM_MB" "$DEPLOYER_CPU_CORES" "$DEPLOYER_DISK_TYPE")

	# Restart PHP-FPM to apply changes
	echo "✓ Restarting PHP-FPM..."
	if ! run_cmd systemctl restart php8.4-fpm; then
		echo "Error: Failed to restart PHP-FPM" >&2
		exit 1
	fi

	# Write output
	if ! cat > "$DEPLOYER_OUTPUT_FILE" << EOF; then
status: success
hardware:
  cpu_cores: $DEPLOYER_CPU_CORES
  ram_mb: $DEPLOYER_RAM_MB
  disk_type: $DEPLOYER_DISK_TYPE
php_fpm_settings: $php_fpm_result
opcache_settings: $opcache_result
EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
