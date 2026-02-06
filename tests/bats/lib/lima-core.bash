#!/usr/bin/env bash

# ----
# Lima Core Functions
# ----
# Shared functions for Lima VM management.
# These functions take explicit parameters and have no external dependencies,
# making them usable in both the test runner (bats.sh) and BATS test context.

# Lima VM instance name prefix
LIMA_PREFIX="deployer-test"

#
# Get Lima instance name for a distro
#
# Arguments:
#   $1 - distro name (required)

lima_instance_name() {
    local distro="$1"
    echo "${LIMA_PREFIX}-${distro}"
}

#
# Check if Lima instance is running
#
# Arguments:
#   $1 - instance name (required)

lima_is_running() {
    local instance="$1"
    limactl list --json 2>/dev/null | jq -e ".[] | select(.name == \"${instance}\" and .status == \"Running\")" >/dev/null 2>&1
}

#
# Check if Lima instance exists
#
# Arguments:
#   $1 - instance name (required)

lima_exists() {
    local instance="$1"
    limactl list --json 2>/dev/null | jq -e ".[] | select(.name == \"${instance}\")" >/dev/null 2>&1
}
