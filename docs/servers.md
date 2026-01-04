# Server Management

- [Introduction](#introduction)
- [Adding a Server](#adding-a-server)
- [Installing a Server](#installing-a-server)
- [Server Information](#server-information)
- [Firewall Configuration](#firewall-configuration)
- [Running Commands](#running-commands)
- [SSH Access](#ssh-access)
- [Viewing Logs](#viewing-logs)
- [Deleting a Server](#deleting-a-server)

<a name="introduction"></a>

## Introduction

DeployerPHP maintains a local inventory of your servers. Before you can deploy sites or install services, you need to add servers to this inventory and install the base packages.

All server commands follow the pattern `server:<action>` and can be run interactively or with command-line options for automation.

<a name="adding-a-server"></a>

## Adding a Server

The `server:add` command adds a new server to your local inventory:

```bash
deployer server:add
```

You'll be prompted for connection details:

| Option               | Description          | Default    |
| -------------------- | -------------------- | ---------- |
| `--name`             | Server name          | (prompted) |
| `--host`             | Host/IP address      | (prompted) |
| `--port`             | SSH port             | 22         |
| `--private-key-path` | SSH private key path | (prompted) |
| `--username`         | SSH username         | root       |

For automation, provide all options on the command line:

```bash
deployer server:add \
    --name=production \
    --host=203.0.113.50 \
    --port=22 \
    --private-key-path=~/.ssh/id_rsa \
    --username=root
```

When adding a server, DeployerPHP connects and gathers information about the server's distribution, hardware, and running services.

<a name="installing-a-server"></a>

## Installing a Server

The `server:install` command prepares a fresh server for hosting PHP applications:

```bash
deployer server:install
```

This installs:

- **Base packages** - git, curl, unzip, and essential utilities
- **Nginx** - Web server with optimized configuration
- **PHP** - Your selected version with extensions
- **Bun** - JavaScript runtime for building assets
- **Deployer user** - Dedicated user for deployments with SSH key

Options:

| Option                  | Description                                                      |
| ----------------------- | ---------------------------------------------------------------- |
| `--server`              | Server name from inventory                                       |
| `--php-version`         | PHP version to install (e.g., 8.3)                               |
| `--php-extensions`      | Comma-separated list of extensions                               |
| `--php-default`         | Set as default PHP version (use `--no-php-default` to skip)      |
| `--generate-deploy-key` | Generate deploy key on server                                    |
| `--custom-deploy-key`   | Path to custom deploy key (expects `.pub` file at same location) |

Example with options:

```bash
deployer server:install \
    --server=production \
    --php-version=8.3 \
    --php-extensions=redis,imagick,gd \
    --generate-deploy-key
```

> [!NOTE]
> After installation, the command displays the server's public key. Add this key to your Git provider to enable access to your repositories.

<a name="server-information"></a>

## Server Information

The `server:info` command displays comprehensive information about a server:

```bash
deployer server:info --server=production
```

This shows:

- **Distribution** - OS name (Ubuntu, Debian)
- **User** - Permission level (root, sudo, or insufficient)
- **Hardware** - CPU cores, RAM, disk type
- **Services** - Listening ports with process names
- **Firewall** - UFW status and open ports
- **Nginx** - Version, active connections, and total requests
- **PHP** - Installed versions with extensions (default version marked)
- **PHP-FPM** - Per-version stats including pool, process counts, queue, and warnings
- **Sites** - Configured domains with HTTPS status and PHP version

<a name="firewall-configuration"></a>

## Firewall Configuration

The `server:firewall` command configures UFW firewall rules:

```bash
deployer server:firewall
```

DeployerPHP detects which services are listening on ports and lets you select which to allow through the firewall. HTTP (80) and HTTPS (443) are pre-selected by default, along with any ports already allowed in UFW.

You'll be prompted to:

- **Select server** - Choose which server to configure
- **Select ports** - Multi-select from detected listening services
- **Confirm changes** - Review and confirm the firewall configuration

Options:

| Option     | Description                                        |
| ---------- | -------------------------------------------------- |
| `--server` | Server name from inventory                         |
| `--allow`  | Comma-separated ports to allow (e.g., 80,443,3306) |
| `--yes`    | Skip confirmation prompt                           |

For automation:

```bash
deployer server:firewall \
    --server=production \
    --allow=80,443,3306 \
    --yes
```

> [!NOTE]
> SSH access is always preserved regardless of your selections. The `--allow` option only accepts ports that have services actively listening on them.

<a name="running-commands"></a>

## Running Commands

The `server:run` command executes arbitrary shell commands on a server:

```bash
deployer server:run
```

You'll be prompted for the server and command. Output is streamed in real-time as the command executes on the remote server.

Options:

| Option      | Description        |
| ----------- | ------------------ |
| `--server`  | Server name        |
| `--command` | Command to execute |

For automation:

```bash
deployer server:run \
    --server=production \
    --command="systemctl status nginx"
```

This is useful for quick administrative tasks without opening a full SSH session.

<a name="ssh-access"></a>

## SSH Access

The `server:ssh` command opens an interactive SSH session:

```bash
deployer server:ssh
```

Before connecting, DeployerPHP displays the server's connection details and any sites configured on that server. You're then dropped into a terminal session on the remote server. Use `exit` to return to your local machine.

Options:

| Option     | Description |
| ---------- | ----------- |
| `--server` | Server name |

For automation:

```bash
deployer server:ssh --server=production
```

<a name="viewing-logs"></a>

## Viewing Logs

The `server:logs` command retrieves logs from various sources on the server:

```bash
deployer server:logs --server=production
```

You can select from:

- **System logs** - journalctl system logs
- **Service logs** - Nginx, SSH, PHP-FPM, databases, cache services
- **Site logs** - Per-site Nginx access logs
- **Cron logs** - Scheduled task output
- **Supervisor logs** - Long-running process output

Options:

| Option          | Description                   | Default    |
| --------------- | ----------------------------- | ---------- |
| `--server`      | Server name                   | (prompted) |
| `--service, -s` | Comma-separated service names | (prompted) |
| `--lines, -n`   | Number of lines to retrieve   | 50         |

Example:

```bash
deployer server:logs \
    --server=production \
    --service=nginx,php8.3-fpm \
    --lines=100
```

<a name="deleting-a-server"></a>

## Deleting a Server

The `server:delete` command removes a server from your inventory:

```bash
deployer server:delete --server=production
```

This command includes safety features:

1. **Type-to-confirm** - You must type the server name to confirm
2. **Double confirmation** - An additional Yes/No prompt

Options:

| Option             | Description                      | Default    |
| ------------------ | -------------------------------- | ---------- |
| `--server`         | Server name to delete            | (prompted) |
| `--force`          | Skip type-to-confirm prompt      | false      |
| `--yes`            | Skip Yes/No confirmation         | false      |
| `--inventory-only` | Only remove from local inventory | false      |

For automation, provide all options on the command line:

```bash
deployer server:delete \
    --server=production \
    --force \
    --yes
```

> [!WARNING]
> If the server was provisioned through DeployerPHP's AWS or DigitalOcean integration, this command will also destroy the cloud resources unless you use `--inventory-only`. For AWS servers, any associated Elastic IP is also released.

When deleting a server, DeployerPHP also removes any associated sites from your inventory. The sites are removed from the inventory only - remote files remain on the server until the cloud instance is destroyed.

For servers provisioned externally, only the inventory entry is removed. The actual server remains running.
