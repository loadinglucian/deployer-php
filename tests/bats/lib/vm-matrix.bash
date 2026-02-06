#!/usr/bin/env bash

# ----
# VM Matrix
# ----
# Canonical distro and SSH port mapping for BATS VM tests.
# Keep this in sync with tests/bats/lima/*.yaml localPort values.

declare -A DISTRO_PORTS=(
	["ubuntu24"]="2224"
)

# Ordered list used by bats.sh menus and iteration.
DISTROS=("ubuntu24")
