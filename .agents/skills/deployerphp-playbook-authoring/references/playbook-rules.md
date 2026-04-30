# Playbook Rules

Playbooks are idempotent, non-interactive bash scripts that execute server tasks remotely. They receive context via environment variables and return YAML output. Bash style: <https://style.ysap.sh/md>

> **IMPORTANT**
>
> - Shebang `#!/usr/bin/env bash` always first line
> - `set -o pipefail` (NEVER use `set -e`)
> - `export DEBIAN_FRONTEND=noninteractive`
> - Validate ALL `DEPLOYER_*` variables before any work
> - NEVER use `eval`
> - Prefer OS-native packages; use third-party repos only when the package is unavailable

## Context

### Environment Variables

All variables use `DEPLOYER_` prefix:

- `DEPLOYER_OUTPUT_FILE` - YAML output path (always required)
- `DEPLOYER_PERMS` - Permissions: `root|sudo|none`

### Available Helpers

These are automatically inlined from `helpers.sh`. Never manually inline helpers into playbook files.

| Function             | Purpose                                             |
| -------------------- | --------------------------------------------------- |
| `run_cmd`            | Execute with appropriate permissions (root or sudo) |
| `run_as_deployer`    | Execute as deployer user with env preservation      |
| `fail "message"`     | Print error and exit                                |
| `detect_php_default` | Get default PHP version                             |
| `wait_for_dpkg_lock` | Wait for package manager lock                       |
| `apt_get_with_retry` | apt-get with automatic retry on lock                |

See `playbooks/server-info.sh` for a simple example.

### Guaranteed System Tools

These tools are installed by `base-install.sh` and available in all playbooks without existence checks:

| Tool         | Purpose              |
| ------------ | -------------------- |
| `jq`         | JSON parsing         |
| `curl`       | HTTP requests        |
| `rsync`      | File synchronization |
| `git`        | Version control      |
| `supervisor` | Process management   |
| `nginx`      | Web server           |
| `certbot`    | SSL certificates     |
| `ufw`        | Firewall management  |

Do NOT add `command -v` checks for these tools - they are guaranteed on provisioned servers.

### Bash Style Rules

**Conditionals:** `[[ ... ]]` not `[ ... ]`

**Command Substitution:** `$(...)` not backticks

**Math:** `((...))` and `$((...))`, never `let`

**Functions:** No `function` keyword, always use `local`

**Block Statements:** `then`/`do` on same line

**Expansion:** Prefer over external commands (`${0##*/}` not `basename "$0"`)

**Quoting:** Double for expansions, single for literals. `[[ ]]` doesn't word-split.

**Arrays:** Use bash arrays, not strings

**Formatting:** Tabs for indentation, max 80 columns, max 1 blank line between sections

### Code Organization

- Section comments: `# ----` + `# Section Name` + `# ----`
- Function comments: `#` + blank + `# Description`
- Group related functions into sections
- Order functions alphabetically within sections
- Place `main()` at the bottom

### Credential Generation

| Service Type | Username Pattern | Example         |
| ------------ | ---------------- | --------------- |
| Database     | `deployer`       | `deployer`      |
| Admin panel  | `admin`          | `admin`         |
| Service auth | `{service}_user` | `rabbitmq_user` |

Use `openssl rand -base64 24` for secure passwords.

### Service Logging

**Systemd services:** Automatic journalctl integration - no configuration needed.

**File-based logs:**

| Service Type   | Log Path Pattern                    |
| -------------- | ----------------------------------- |
| Direct service | `/var/log/{service}*.log`           |
| Subdirectory   | `/var/log/{service}/*.log`          |
| Per-site       | `/var/log/{service}/{domain}-*.log` |

### Log Rotation

Default to built-in rotation. Only create custom configs when:

1. Service has no built-in rotation (unlike Nginx)
2. System package provides no default config (unlike PHP-FPM)
3. You need per-resource rotation (e.g., per-site, per-program)

| Strategy       | When to Use                                      |
| -------------- | ------------------------------------------------ |
| `copytruncate` | Service keeps file handles open (Supervisor)     |
| `postrotate`   | Service supports signal/reload for log reopening |

## Examples

### Example: Playbook Structure

```bash
#!/usr/bin/env bash

#

# {Playbook Name} Playbook - Ubuntu Only

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

# DEPLOYER_OUTPUT_FILE - Output file path

# DEPLOYER_PERMS - Permissions: root|sudo|none

# {DEPLOYER_CUSTOM_VAR} - {Description}

#

# Returns YAML with:

# - status: success

# - {key}: {description}

#

set -o pipefail
export DEBIAN_FRONTEND=noninteractive

[[ -z $DEPLOYER_OUTPUT_FILE ]] && echo "Error: DEPLOYER_OUTPUT_FILE required" && exit 1
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

function_name() { # Implementation
}

# ----

# Main Execution

# ----

main() { # Execute tasks
function_name

    # Write output YAML
    if ! cat > "$DEPLOYER_OUTPUT_FILE" <<EOF; then
status: success
EOF
        echo "Error: Failed to write output file" >&2
        exit 1
    fi

}

main "$@"
```

### Example: Idempotency Command

```bash
# Command existence
if ! command -v nginx >/dev/null 2>&1; then
    echo "→ Installing Nginx..."
    run_cmd apt-get install -y -q nginx
fi
```

### Example: Idempotency Directory

```bash
# Directory existence
if ! run_cmd test -d /var/www/app; then
    echo "→ Creating /var/www/app..."
    run_cmd mkdir -p /var/www/app
fi
```

### Example: Idempotency File

```bash
# File existence
if ! run_cmd test -f "$config_file"; then
    echo "→ Creating configuration..."
    run_cmd tee "$config_file" > /dev/null <<- 'EOF'
        # config content
    EOF
fi
```

### Example: Idempotency Marker

```bash
# Config content marker
if ! grep -q "DEPLOYER-MARKER" /etc/config 2>/dev/null; then
    echo "→ Updating configuration..."
    # modify config
fi
```

### Example: Idempotency Service

```bash
# Service state
if ! systemctl is-enabled --quiet service 2>/dev/null; then
    run_cmd systemctl enable --quiet service
fi
```

### Example: Error Validation

```bash
# Validation errors → stdout, then exit
[[ -z $DEPLOYER_VAR ]] && echo "Error: DEPLOYER_VAR required" && exit 1
```

### Example: Error Runtime

```bash
# Runtime errors → stderr, then exit
if ! run_cmd mkdir -p /var/www/app 2>&1; then
    echo "Error: Failed to create directory" >&2
    exit 1
fi

# Inline error check

cd /path || exit

# Using fail helper

run_cmd chown deployer:deployer /path || fail "Failed to set ownership"
```

### Example: Action Message (Correct)

```bash
# CORRECT - Explicit paths/names/versions
echo "→ Creating /var/www/app directory..."
echo "→ Installing PHP 8.4..."
echo "→ Configuring Nginx for example.com..."

# Message INSIDE conditional block
if ! command -v nginx >/dev/null 2>&1; then
    echo "→ Installing Nginx..."
    run_cmd apt-get install -y -q nginx
fi
```

### Example: Action Message (Wrong)

```bash
# WRONG - Generic messages
echo "→ Creating directory..."
echo "→ Installing package..."
```

### Example: Package Installation

```bash
# Install packages (Ubuntu only)
local packages=(software-properties-common curl zip unzip)

apt_get_with_retry install -y -q "${packages[@]}"
```

### Example: Non-Interactive Apt

```bash
# Adding a repository key (non-interactive)
curl -fsSL https://example.com/key.gpg | gpg --batch --yes --dearmor -o /etc/apt/keyrings/example.gpg
```

### Example: User Script

```bash
#
# Execute user script if it exists
#
# Arguments:
#   $1 - Script path
#   $2 - Script description (for error messages)

run_user_script() {
    local script_path=$1
    local script_desc=${2:-script}

    if [[ ! -f $script_path ]]; then
        return 0
    fi

    if [[ ! -x $script_path ]]; then
        chmod +x "$script_path" || fail "Failed to make ${script_desc} executable"
        run_as_deployer chmod +x "$script_path" > /dev/null 2>&1 || true
    fi

    echo "→ Running ${script_desc}..."

    if ! run_as_deployer "$script_path"; then
        fail "${script_desc} failed"
    fi

}

# Usage

run_user_script "${RELEASE_PATH}/.deployer/hooks/1-building.sh" "1-building.sh hook"
```

### Example: YAML Output Simple

```bash
# Simple success
if ! cat > "$DEPLOYER_OUTPUT_FILE" <<EOF; then
status: success
EOF
    echo "Error: Failed to write output file" >&2
    exit 1
fi
```

### Example: YAML Output Data

```bash
# With additional data
if ! cat > "$DEPLOYER_OUTPUT_FILE" <<EOF; then
status: success
site_path: ${site_path}
php_version: ${php_version}
EOF
    echo "Error: Failed to write output file" >&2
    exit 1
fi
```

### Example: Credential Generation

```bash
main() {
    local db_user="deployer"
    local db_pass
    db_pass=$(openssl rand -base64 24)

    # ... service installation ...

    if ! cat > "$DEPLOYER_OUTPUT_FILE" <<EOF; then
status: success
mysql_user: ${db_user}
mysql_pass: ${db_pass}
EOF
        echo "Error: Failed to write output file" >&2
        exit 1
    fi

}
```

### Example: Logrotate Config

```bash
# Per-supervisor-program logrotate config
local logrotate_file="${LOGROTATE_DIR}/supervisor-${domain}-${program}.conf"

if ! run_cmd tee "$logrotate_file" > /dev/null <<EOF; then
/var/log/supervisor/${domain}-${program}.log {
    daily
    rotate 5
    maxage 30
    missingok
    notifempty
    compress
    delaycompress
    copytruncate
}
EOF
    echo "Error: Failed to write ${logrotate_file}" >&2
    exit 1
fi
```

### Example: Log Directory

```bash
if [[ ! -d /var/log/myservice ]]; then
    echo "→ Creating /var/log/myservice directory..."
    run_cmd mkdir -p /var/log/myservice
    run_cmd chown myservice:myservice /var/log/myservice
fi
```

## Rules

- ALWAYS check before acting (idempotency)
- Validation errors → stdout; Runtime errors → stderr
- Use `→` prefix for action messages with explicit paths/names/versions
- Never manually inline helpers into playbooks
- Include helper placeholder comment after header validation
- Write YAML output at end of `main()`
- Always wrap YAML write in error check
- Document credential returns in playbook header
- Create logrotate configs at resource creation time, not base installation
- Remove logrotate configs when removing resources (orphan cleanup)
