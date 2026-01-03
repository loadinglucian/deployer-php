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

| Option               | Description                  | Default    |
| -------------------- | ---------------------------- | ---------- |
| `--name`             | Friendly name for the server | (prompted) |
| `--host`             | IP address or hostname       | (prompted) |
| `--port`             | SSH port                     | 22         |
| `--username`         | SSH username                 | root       |
| `--private-key-path` | Path to SSH private key      | (prompted) |

For automation, provide all options on the command line:

```bash
deployer server:add \
    --name=production \
    --host=203.0.113.50 \
    --port=22 \
    --username=root \
    --private-key-path=~/.ssh/id_rsa
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

| Option                  | Description                        |
| ----------------------- | ---------------------------------- |
| `--server`              | Server name from inventory         |
| `--php-version`         | PHP version to install (e.g., 8.3) |
| `--php-extensions`      | Comma-separated list of extensions |
| `--php-default`         | Set as default PHP version         |
| `--generate-deploy-key` | Generate deploy key on server      |
| `--custom-deploy-key`   | Path to custom deploy key          |

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

- **Distribution** - OS name and version
- **Hardware** - CPU cores, RAM, disk type
- **Services** - Running services with ports
- **Firewall** - UFW status and allowed ports
- **Nginx** - Version and connection stats
- **PHP** - Installed versions and extensions
- **PHP-FPM Pools** - Process manager stats per version
- **Sites** - Configured sites with HTTPS status

<a name="firewall-configuration"></a>

## Firewall Configuration

The `server:firewall` command configures UFW firewall rules:

```bash
deployer server:firewall --server=production
```

DeployerPHP detects which services are listening on ports and lets you select which to allow through the firewall. HTTP (80) and HTTPS (443) are pre-selected by default.

For automation:

```bash
deployer server:firewall \
    --server=production \
    --allow=80,443,3306 \
    --yes
```

> [!NOTE]
> SSH access is always preserved regardless of your selections.

<a name="running-commands"></a>

## Running Commands

The `server:run` command executes arbitrary shell commands on a server:

```bash
deployer server:run --server=production
```

You'll be prompted to enter the command. Output is streamed in real-time.

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
deployer server:ssh --server=production
```

This drops you into a terminal on the server. Use `exit` to return to your local machine.

<a name="viewing-logs"></a>

## Viewing Logs

The `server:logs` command retrieves logs from various sources on the server:

```bash
deployer server:logs --server=production
```

You can select from:

- **System logs** - journalctl system logs
- **Service logs** - Nginx, PHP-FPM, databases, cache services
- **Site logs** - Per-site access and error logs
- **Cron logs** - Scheduled task output
- **Supervisor logs** - Long-running process output

Options:

| Option      | Description                   | Default    |
| ----------- | ----------------------------- | ---------- |
| `--server`  | Server name                   | (prompted) |
| `--service` | Comma-separated service names | (prompted) |
| `--lines`   | Number of lines to retrieve   | 50         |

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

| Option             | Description                      |
| ------------------ | -------------------------------- |
| `--server`         | Server name to delete            |
| `--force`          | Skip type-to-confirm prompt      |
| `--yes`            | Skip Yes/No confirmation         |
| `--inventory-only` | Only remove from local inventory |

> [!WARNING]
> If the server was provisioned through DeployerPHP's AWS or DigitalOcean integration, this command will also destroy the cloud resources unless you use `--inventory-only`.

For servers provisioned externally, only the inventory entry is removed. The actual server remains running.
