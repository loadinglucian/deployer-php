#!/usr/bin/env bash

# ----
# Inventory Manipulation Helpers
# ----

# Reset test inventory to empty state
# Usage: reset_inventory
reset_inventory() {
    cat > "$TEST_INVENTORY" << 'EOF'
servers: []
sites: []
EOF
}

# Add test server to inventory
# Usage: add_test_server [name]
add_test_server() {
    local name="${1:-$TEST_SERVER_NAME}"
    cat > "$TEST_INVENTORY" << EOF
servers:
  - name: ${name}
    host: ${TEST_SERVER_HOST}
    port: ${TEST_SERVER_PORT}
    username: ${TEST_SERVER_USER}
    privateKeyPath: ${TEST_KEY}
sites: []
EOF
}

# Add test server with a site to inventory
# Usage: add_test_site "example.com" [php_version]
add_test_site() {
    local domain="${1:-example.com}"
    local php_version="${2:-8.4}"
    cat > "$TEST_INVENTORY" << EOF
servers:
  - name: ${TEST_SERVER_NAME}
    host: ${TEST_SERVER_HOST}
    port: ${TEST_SERVER_PORT}
    username: ${TEST_SERVER_USER}
    privateKeyPath: ${TEST_KEY}
sites:
  - domain: ${domain}
    server: ${TEST_SERVER_NAME}
    phpVersion: "${php_version}"
EOF
}

# Add multiple servers to inventory
# Usage: add_multiple_servers "server1" "server2"
add_multiple_servers() {
    local servers_yaml="servers:"
    for name in "$@"; do
        servers_yaml+="
  - name: ${name}
    host: ${TEST_SERVER_HOST}
    port: ${TEST_SERVER_PORT}
    username: ${TEST_SERVER_USER}
    privateKeyPath: ${TEST_KEY}"
    done

    cat > "$TEST_INVENTORY" << EOF
${servers_yaml}
sites: []
EOF
}

# Check if server exists in inventory
# Usage: inventory_has_server "test-server"
inventory_has_server() {
    local name="$1"
    grep -q "name: ${name}" "$TEST_INVENTORY"
}

# Check if site exists in inventory
# Usage: inventory_has_site "example.com"
inventory_has_site() {
    local domain="$1"
    grep -q "domain: ${domain}" "$TEST_INVENTORY"
}

# Get server count in inventory
# Usage: inventory_server_count
inventory_server_count() {
    grep -c "^  - name:" "$TEST_INVENTORY" 2>/dev/null || echo "0"
}

# Get site count in inventory
# Usage: inventory_site_count
inventory_site_count() {
    grep -c "^  - domain:" "$TEST_INVENTORY" 2>/dev/null || echo "0"
}

# Print inventory contents for debugging
# Usage: debug_inventory
debug_inventory() {
    if [[ "${BATS_DEBUG:-0}" == "1" ]]; then
        echo "# INVENTORY CONTENTS:" >&3
        cat "$TEST_INVENTORY" | sed 's/^/# /' >&3
    fi
}
