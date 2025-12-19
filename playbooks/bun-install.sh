#!/usr/bin/env bash

#
# Bun Installation
#
# Installs Bun JavaScript runtime system-wide to /usr/local.
#
# Output:
#   status: success
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# Main Execution
# ----

main() {
	if command -v bun > /dev/null 2>&1; then
		echo "Bun is already installed (run 'bun upgrade' manually to upgrade if needed)"
	else
		echo "→ Installing Bun..."
		# Install Bun system-wide to /usr/local
		if ! curl -fsSL https://bun.sh/install | run_cmd env BUN_INSTALL=/usr/local bash; then
			echo "Error: Failed to install Bun" >&2
			exit 1
		fi
	fi

	# Write output YAML
	if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
		status: success
	EOF
		echo "Error: Failed to write output file" >&2
		exit 1
	fi
}

main "$@"
