---
name: playbook
description: Use this skill when writing, creating, or modifying bash playbook scripts in the playbooks/ directory. Activates for tasks involving server provisioning, site deployment, package installation, or any idempotent bash automation scripts.
---

# Playbook Development

Playbooks are idempotent, non-interactive bash scripts that execute server tasks remotely. They receive context via environment variables and return YAML output.

All rules MANDATORY. Bash style based on <https://style.ysap.sh/md>

## Required Structure

Every playbook MUST follow this exact structure:

```bash
#!/usr/bin/env bash

#
# {Playbook Name} Playbook - Ubuntu/Debian Only
#
# {Brief description of what this playbook does}
# ----
#
# {Detailed description including:}
# {- What the playbook installs/configures}
# {- Prerequisites or dependencies}
# {- Any important notes}
#
# Required Environment Variables:
#   DEPLOYER_OUTPUT_FILE  - Output file path
#   DEPLOYER_DISTRO       - Exact distribution: ubuntu|debian
#   DEPLOYER_PERMS        - Permissions: root|sudo|none
#   {DEPLOYER_CUSTOM_VAR} - {Description}
#
# Returns YAML with:
#   - status: success
#   - {key}: {description}
#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
[[ -z $DEPLOYER_DISTRO ]] && echo "Error: DEPLOYER_DISTRO required" && exit 1
[[ -z $DEPLOYER_PERMS ]] && echo "Error: DEPLOYER_PERMS required" && exit 1
# Add validation for custom variables here
export DEPLOYER_PERMS

# Shared helpers are automatically inlined when executing playbooks remotely
# source "$(dirname "$0")/helpers.sh"

# ----
# {Section Name} Functions
# ----

#
# {Function description}

function_name() {
    # Implementation
}

# ----
# Main Execution
# ----

main() {
    # Execute tasks
    function_name

    # Write output YAML
    if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
        status: success
    EOF
        echo "Error: Failed to write output file" >&2
        exit 1
    fi
}

main "$@"
```

## Critical Requirements

### Header Block

- Shebang: `#!/usr/bin/env bash` (always first line)
- `set -o pipefail` (NEVER use `set -e`)
- `export DEBIAN_FRONTEND=noninteractive`
- Validate ALL required `DEPLOYER_*` variables before any work
- `export DEPLOYER_PERMS` after validation

### Environment Variables

All variables use `DEPLOYER_` prefix:

- `DEPLOYER_OUTPUT_FILE` - YAML output path (always required)
- `DEPLOYER_DISTRO` - Distribution: `ubuntu|debian`
- `DEPLOYER_PERMS` - Permissions: `root|sudo|none`

### Available Helper Functions

These are automatically inlined from `helpers.sh`. Never manually inline helpers into playbook files.

| Function | Purpose |
|----------|---------|
| `run_cmd` | Execute with appropriate permissions (root or sudo) |
| `run_as_deployer` | Execute as deployer user with env preservation |
| `fail "message"` | Print error and exit |
| `detect_php_default` | Get default PHP version |
| `wait_for_dpkg_lock` | Wait for package manager lock |
| `apt_get_with_retry` | apt-get with automatic retry on lock |
| `link_shared_resources` | Link shared resources to release |

See `playbooks/server-info.sh` for a simple example.

## Non-Interactive Operation

Playbooks must never prompt for user input:

- `export DEBIAN_FRONTEND=noninteractive` - Prevents apt prompts
- Package managers: `-y -q` flags (`apt-get install -y -q`)
- GPG: `--batch --yes` flags (`gpg --batch --yes --dearmor`)
- Never use `read` or interactive prompts

```bash
# Adding a repository key (non-interactive)
curl -fsSL https://example.com/key.gpg | gpg --batch --yes --dearmor -o /etc/apt/keyrings/example.gpg
```

## Idempotency Patterns

ALWAYS check before acting:

```bash
# Command existence
if ! command -v caddy >/dev/null 2>&1; then
    echo "→ Installing Caddy..."
    run_cmd apt-get install -y -q caddy
fi

# Directory existence
if ! run_cmd test -d /var/www/app; then
    echo "→ Creating /var/www/app..."
    run_cmd mkdir -p /var/www/app
fi

# File existence
if ! run_cmd test -f "$config_file"; then
    echo "→ Creating configuration..."
    run_cmd tee "$config_file" > /dev/null <<- 'EOF'
        # config content
    EOF
fi

# Config content marker
if ! grep -q "DEPLOYER-MARKER" /etc/config 2>/dev/null; then
    echo "→ Updating configuration..."
    # modify config
fi

# Service state
if ! systemctl is-enabled --quiet service 2>/dev/null; then
    run_cmd systemctl enable --quiet service
fi
```

## Error Handling

```bash
# Validation errors → stdout, then exit
[[ -z $DEPLOYER_VAR ]] && echo "Error: DEPLOYER_VAR required" && exit 1

# Runtime errors → stderr, then exit
if ! run_cmd mkdir -p /var/www/app 2>&1; then
    echo "Error: Failed to create directory" >&2
    exit 1
fi

# Inline error check
cd /path || exit

# Using fail helper
run_cmd chown deployer:deployer /path || fail "Failed to set ownership"

# YAML output write check
if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
    status: success
EOF
    echo "Error: Failed to write output file" >&2
    exit 1
fi
```

Rules:

- Use `set -o pipefail` but NOT `set -e` - explicit checking preferred
- Never use `eval`

## Action Messages

Use `→` prefix. Be explicit with paths/names/versions:

```bash
# CORRECT - Explicit
echo "→ Creating /var/www/app directory..."
echo "→ Installing PHP 8.4..."
echo "→ Configuring Caddy for example.com..."

# WRONG - Generic
echo "→ Creating directory..."
echo "→ Installing package..."

# Message INSIDE conditional block
if ! command -v caddy >/dev/null 2>&1; then
    echo "→ Installing Caddy..."
    run_cmd apt-get install -y -q caddy
fi
```

## Distribution Handling

Only branch when Ubuntu and Debian differ:

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

# Universal commands (no branching needed)
run_cmd apt-get update -q
apt_get_with_retry install -y -q "${packages[@]}"
```

## Bash Style Rules

### Core Syntax

**Conditionals:** `[[ ... ]]` not `[ ... ]`

```bash
[[ -d /etc ]]              # CORRECT
[ -d /etc ]                # WRONG
```

**Command Substitution:** `$(...)` not backticks

```bash
foo=$(date)                # CORRECT
foo=`date`                 # WRONG
```

**Math:** `((...))` and `$((...))`, never `let`

```bash
if ((a > b)); then ...     # CORRECT
if [[ $a -gt $b ]]; then   # WRONG
```

**Functions:** No `function` keyword, always use `local`

```bash
foo() { local i=5; }       # CORRECT
function foo { i=5; }      # WRONG
```

**Block Statements:** `then`/`do` on same line

```bash
if true; then ...          # CORRECT
while true; do ...         # CORRECT
```

### Parameter Handling

**Expansion:** Prefer over external commands

```bash
prog=${0##*/}              # CORRECT - basename
nonumbers=${name//[0-9]/}  # CORRECT - remove numbers
prog=$(basename "$0")      # WRONG - external command
```

**Quoting:** Double for expansions, single for literals

```bash
echo "$foo"                # expansion needs quotes
bar='literal'              # no expansion
if [[ -n $foo ]]; then     # [[ ]] doesn't word-split
```

**Arrays:** Use bash arrays, not strings

```bash
modules=(a b c)            # CORRECT
for m in "${modules[@]}"   # CORRECT
modules='a b c'            # WRONG
```

### File Operations

```bash
# Streaming read
while IFS=: read -r user _; do
    echo "$user"
done < /etc/passwd

# CORRECT
grep foo file

# WRONG - useless cat
cat file | grep foo

# CORRECT - globs
for f in *; do ...

# WRONG - parsing ls
for f in $(ls); do ...
```

### Formatting

- Tabs for indentation
- Max 80 columns
- Semicolons only in control statements
- Max 1 blank line between sections

## Code Organization

### Section Comments

```bash
# ----
# Section Name
# ----
```

### Function Comments

```bash
#
# Brief description of what function does

function_name() {
    # implementation
}
```

### Grouping

- Group related functions into comment-separated sections
- Order functions alphabetically within sections after grouping
- Place `main()` at the bottom

## YAML Output

Always write YAML output at end of `main()`:

```bash
# Simple success
if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
    status: success
EOF
    echo "Error: Failed to write output file" >&2
    exit 1
fi

# With additional data
if ! cat > "$DEPLOYER_OUTPUT_FILE" <<- EOF; then
    status: success
    site_path: ${site_path}
    php_version: ${php_version}
EOF
    echo "Error: Failed to write output file" >&2
    exit 1
fi
```
