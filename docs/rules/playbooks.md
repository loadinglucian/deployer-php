# Playbook Rules

All rules MANDATORY.

## Core Principles

Playbooks are idempotent, non-interactive bash scripts that:
- Execute one or more related tasks
- MUST be idempotent (safe to run multiple times)
- Receive context via environment variables
- Never prompt for user input
- Return parsable YAML output

## Structure

```bash
#!/usr/bin/env bash
set -o pipefail
export DEBIAN_FRONTEND=noninteractive

# Validation
[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
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
# Main Execution
# ----

main() {
    # Tasks go here

    if ! cat > "$DEPLOYER_OUTPUT_FILE" <<EOF; then
status: success
EOF
        echo "Error: Failed to write output file" >&2
        exit 1
    fi
}

main "$@"
```

**Requirements:**
- Shebang: `#!/usr/bin/env bash`
- Always `set -o pipefail` (NOT `set -e`)
- Export `DEBIAN_FRONTEND=noninteractive`
- Validate `$DEPLOYER_OUTPUT_FILE` before any work
- Use `main()` function with `main "$@"` at bottom

## Environment Variables

Use `DEPLOYER_` prefix:
- `DEPLOYER_OUTPUT_FILE` - YAML output path (automatic)
- `DEPLOYER_DISTRO` - Distribution: `ubuntu|debian`
- `DEPLOYER_PERMS` - Permissions: `root|sudo|none`

## Distribution Support

Ubuntu and Debian only. Use `case` only when they differ:

```bash
# When distributions differ
case $DEPLOYER_DISTRO in
    ubuntu)
        distro_packages=(software-properties-common)
        ;;
    debian)
        distro_packages=(apt-transport-https lsb-release)
        ;;
esac

# Universal (no branching)
run_cmd apt-get update -q
run_cmd apt-get install -y -q caddy
```

## Non-Interactive Operation

- `export DEBIAN_FRONTEND=noninteractive`
- Package managers: `-y -q` flags
- GPG: `--batch --yes`
- Never use `read` or interactive prompts

## Idempotency

Check before acting:

```bash
if ! command -v caddy >/dev/null 2>&1; then
    echo "→ Installing Caddy..."
    run_cmd apt-get install -y -q caddy
fi

if [[ ! -d /var/www/app ]]; then
    echo "→ Creating /var/www/app..."
    run_cmd mkdir -p /var/www/app
fi

# For config files, check for custom content markers
if ! grep -q "import conf.d/localhost.caddy" /etc/caddy/Caddyfile 2>/dev/null; then
    echo "→ Creating Caddyfile..."
    run_cmd tee /etc/caddy/Caddyfile > /dev/null <<- 'EOF'
        # custom config
    EOF
fi
```

## Error Handling

Use `set -o pipefail` but NOT `set -e`. Check explicitly:

```bash
# Validation → stdout
[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: required" && exit 1

# Runtime → stderr
if ! mkdir -p /var/www/app 2>&1; then
    echo "Error: Failed to create directory" >&2
    exit 1
fi

# Check YAML writes
if ! cat > "$DEPLOYER_OUTPUT_FILE" <<EOF; then
status: success
EOF
    echo "Error: Failed to write output" >&2
    exit 1
fi
```

## Action Messages

Use `→` prefix. Be explicit with paths/names/versions:

```bash
# CORRECT - Explicit
echo "→ Creating /var/www/app directory..."
echo "→ Installing PHP 8.5..."

# WRONG - Generic
echo "→ Creating directory..."
echo "→ Installing package..."

# Conditional ops - message INSIDE block
if ! command -v caddy >/dev/null 2>&1; then
    echo "→ Installing Caddy..."
    run_cmd apt-get install -y -q caddy
fi
```

## Shared Helpers

Helpers from `helpers.sh` are automatically inlined during remote execution:

```bash
# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"
```

Never manually inline helpers into playbook files.

See: `playbooks/server-info.sh`
