#!/usr/bin/env bash

# ----
# Lima VM Lifecycle Management
# ----

# Lima VM instance name prefix
LIMA_PREFIX="deployer-test"

# Get Lima instance name for a distro
# Usage: lima_instance_name "ubuntu24"
lima_instance_name() {
    local distro="${1:-$BATS_DISTRO}"
    echo "${LIMA_PREFIX}-${distro}"
}

# Check if Lima instance is running
# Usage: lima_is_running
lima_is_running() {
    local instance
    instance="$(lima_instance_name)"
    limactl list --json 2>/dev/null | jq -e ".[] | select(.name == \"${instance}\" and .status == \"Running\")" >/dev/null 2>&1
}

# Check if Lima instance exists
# Usage: lima_exists
lima_exists() {
    local instance
    instance="$(lima_instance_name)"
    limactl list --json 2>/dev/null | jq -e ".[] | select(.name == \"${instance}\")" >/dev/null 2>&1
}

# Start Lima instance
# Usage: lima_start
lima_start() {
    local instance
    instance="$(lima_instance_name)"
    local config="${BATS_TEST_ROOT}/lima/${BATS_DISTRO}.yaml"

    if lima_is_running; then
        return 0
    fi

    if lima_exists; then
        limactl start "$instance"
    else
        limactl start --name="$instance" "$config"
    fi
}

# Stop Lima instance
# Usage: lima_stop
lima_stop() {
    local instance
    instance="$(lima_instance_name)"
    if lima_exists; then
        limactl stop "$instance" 2>/dev/null || true
    fi
}

# Reset Lima instance to clean state (delete and recreate)
# Usage: lima_reset
lima_reset() {
    local instance
    instance="$(lima_instance_name)"

    if lima_exists; then
        limactl stop "$instance" 2>/dev/null || true
        limactl delete "$instance" --force 2>/dev/null || true
    fi

    lima_start
}

# Wait for SSH to be ready
# Usage: lima_wait_ssh [max_attempts]
lima_wait_ssh() {
    local max_attempts="${1:-60}"
    local attempt=0

    while [[ $attempt -lt $max_attempts ]]; do
        if ssh -i "$TEST_KEY" \
            -o StrictHostKeyChecking=no \
            -o UserKnownHostsFile=/dev/null \
            -o ConnectTimeout=2 \
            -o LogLevel=ERROR \
            -p "$TEST_SERVER_PORT" \
            "${TEST_SERVER_USER}@${TEST_SERVER_HOST}" \
            "echo ok" >/dev/null 2>&1; then
            return 0
        fi
        ((attempt++))
        sleep 1
    done

    return 1
}

# Clean VM state (faster than full reset)
# Cleans up deployer-created artifacts without restarting VM
# Usage: lima_clean
lima_clean() {
    # Remove deployer-created files and directories
    ssh_exec "rm -rf /home/deployer/sites/* 2>/dev/null || true"

    # Stop and clean up any installed services
    ssh_exec "systemctl stop caddy 2>/dev/null || true"
    ssh_exec "rm -rf /etc/caddy/sites/* 2>/dev/null || true"

    # Clean up supervisor programs
    ssh_exec "rm -rf /etc/supervisor/conf.d/deployer-* 2>/dev/null || true"
    ssh_exec "supervisorctl reread 2>/dev/null || true"

    # Clean up cron jobs
    ssh_exec "crontab -r 2>/dev/null || true"
}

# Execute a command inside the VM via limactl shell
# Usage: lima_exec "apt-get update"
lima_exec() {
    local instance
    instance="$(lima_instance_name)"
    limactl shell "$instance" sudo bash -c "$1"
}

# Get VM logs (journalctl)
# Usage: lima_logs [lines]
lima_logs() {
    local lines="${1:-50}"
    ssh_exec "journalctl -n ${lines} --no-pager"
}
