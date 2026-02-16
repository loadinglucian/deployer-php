# Command Reference: Server

<!-- toc -->

- [server:add](#server-add)
- [server:install](#server-install)
- [server:info](#server-info)
- [server:firewall](#server-firewall)
- [server:logs](#server-logs)
- [server:run](#server-run)
- [server:ssh](#server-ssh)
- [server:delete](#server-delete)

<!-- /toc -->

Use these commands to manage server inventory, bootstrap runtime dependencies, inspect runtime state, and perform operational tasks over SSH.

<a name="server-add"></a>

## server:add

- **What it does**: Adds a server connection to inventory after validating connectivity and host details.
- **When to use it**: When onboarding a new VPS, cloud instance, or physical server.
- **Prerequisites**: Reachable host, valid SSH credentials, and a private key path available locally.
- **Effects on server/inventory/resources**: Writes a new server entry to local inventory.
- **Related commands**: `server:install`, `server:info`, `server:delete`.
- **Failure/guardrail behavior**: Fails early on invalid server details, SSH auth failures, or duplicate inventory names.

<a name="server-install"></a>

## server:install

- **What it does**: Installs and configures the baseline runtime stack so the server can host PHP applications.
- **When to use it**: Immediately after adding a server, and later when expanding installed runtime components.
- **Prerequisites**: Server must already exist in inventory and be reachable over SSH.
- **Effects on server/inventory/resources**: Changes remote server packages and system configuration; inventory remains unchanged.
- **Related commands**: `server:add`, `server:info`, `nginx:start`, `php:restart`.
- **Failure/guardrail behavior**: Aborts on unsupported distro/runtime checks and surfaces service-level installation failures with context.

<a name="server-info"></a>

## server:info

- **What it does**: Displays server state, installed services, runtime versions, and site-level deployment context.
- **When to use it**: During audits, troubleshooting, and post-install verification.
- **Prerequisites**: Server must be present in inventory and reachable.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `server:logs`, `server:run`, `server:firewall`.
- **Failure/guardrail behavior**: Stops with actionable errors when SSH or server information checks cannot complete.

<a name="server-firewall"></a>

## server:firewall

- **What it does**: Applies UFW firewall rules based on selected service ports.
- **When to use it**: After installing services or when tightening server network exposure.
- **Prerequisites**: UFW available on target server and administrative privileges over SSH.
- **Effects on server/inventory/resources**: Updates remote firewall rules.
- **Related commands**: `server:info`, `server:logs`.
- **Failure/guardrail behavior**: Preserves SSH access guardrails and halts when firewall rule changes fail.

<a name="server-logs"></a>

## server:logs

- **What it does**: Streams selected server, service, site, cron, and supervisor logs.
- **When to use it**: Root-cause analysis, post-deploy verification, and runtime monitoring.
- **Prerequisites**: Server reachable via SSH and relevant log sources available.
- **Effects on server/inventory/resources**: Read-only operation.
- **Related commands**: `server:info`, `site:deploy`, `supervisor:sync`.
- **Failure/guardrail behavior**: Returns clear errors when selected log sources are unavailable or remote reads fail.

<a name="server-run"></a>

## server:run

- **What it does**: Executes an arbitrary command on a selected server over SSH.
- **When to use it**: One-off diagnostics or maintenance that do not require an interactive shell.
- **Prerequisites**: Valid server inventory entry and command string to execute remotely.
- **Effects on server/inventory/resources**: Depends on the command being run.
- **Related commands**: `server:ssh`, `server:info`, `server:logs`.
- **Failure/guardrail behavior**: Returns non-zero exits and preserves command stderr/stdout for troubleshooting.

<a name="server-ssh"></a>

## server:ssh

- **What it does**: Opens an interactive SSH session to a selected server.
- **When to use it**: Manual multi-step operations that are easier in a terminal session.
- **Prerequisites**: Local `pcntl` support and working SSH credentials.
- **Effects on server/inventory/resources**: No direct changes unless you run mutating commands in the remote shell.
- **Related commands**: `site:ssh`, `server:run`.
- **Failure/guardrail behavior**: Stops when interactive session prerequisites are missing or SSH negotiation fails.

<a name="server-delete"></a>

## server:delete

- **What it does**: Removes a server from inventory and can also remove linked cloud resources when applicable.
- **When to use it**: Decommissioning a server or cleaning up stale inventory entries.
- **Prerequisites**: Target server must exist in inventory.
- **Effects on server/inventory/resources**: Removes local inventory entry and optionally destroys provider-side resources.
- **Related commands**: `server:add`, `aws:provision`, `do:provision`.
- **Failure/guardrail behavior**: Requires explicit confirmation and warns when cloud deletion fails before finalizing inventory removal.
