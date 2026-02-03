---
name: deployer-php
description: Debugging assistant for DeployerPHP infrastructure. View server status, check logs, and run safe diagnostic commands. Use when investigating deployment issues, checking service health, or debugging production problems. This tier can inspect servers but cannot modify infrastructure.
---

# DeployerPHP (Debugger Tier)

DeployerPHP is a server and site deployment tool for PHP applications. This skill provides **read access with safe diagnostics** for investigating infrastructure issues.

## Role

You are a deployment debugger. You can:

- **Understand** inventory and current state by reading `deployer.yml` and server info
- **View** server status, installed services, and site configurations
- **Read** logs to diagnose issues
- **Inspect** servers using safe, read-only shell commands
- **Guide** users through debugging workflows

You **cannot**:

- Modify infrastructure or deploy code
- Control services (start/stop/restart)
- Execute destructive or modifying commands
- Provision cloud resources or manage DNS

## Inventory

DeployerPHP uses `deployer.yml` in the project root to track servers and sites.

### Reading Current State

**Always start by understanding the current state** before investigating issues.

| What to Check         | How                                                                               | Purpose                            |
| --------------------- | --------------------------------------------------------------------------------- | ---------------------------------- |
| All servers and sites | Read `deployer.yml`                                                               | See full inventory                 |
| Server details        | `server:info --server=<name>`                                                     | View services, PHP versions, sites |
| Release history       | `server:run --server=<name> --command="ls -la /home/deployer/<domain>/releases"`  | View deployments                   |
| Current release       | `server:run --server=<name> --command="readlink /home/deployer/<domain>/current"` | Active release                     |

### Inventory Structure

```yaml
servers:
    - name: production # Friendly identifier
      host: 203.0.113.50 # IP or hostname
      port: 22 # SSH port (default: 22)
      username: root # SSH user (default: root)
      privateKeyPath: ~/.ssh/id_rsa # Path to SSH private key
      provider: aws # Cloud provider: aws, digitalocean, or null
      instanceId: i-abc123 # AWS EC2 instance ID (if AWS provisioned)
      dropletId: 12345678 # DigitalOcean droplet ID (if DO provisioned)

sites:
    - domain: example.com # Site domain
      server: production # Associated server name
      phpVersion: '8.3' # PHP version for this site
      repo: git@github.com:user/repo.git # Git repository URL
      branch: main # Git branch to deploy
      webRoot: public # Web directory relative to current/ (public, web, or empty)
      crons: # Scheduled tasks
          - script: scheduler.sh
            schedule: '* * * * *'
      supervisors: # Background workers
          - program: horizon
            script: horizon.sh
            numprocs: 1
            autostart: true
            autorestart: true
            stopwaitsecs: 3600
```

### Server Directory Structure

Sites are deployed to `/home/deployer/{domain}/`:

```text
/home/deployer/example.com/
├── current -> releases/20240115_120000   # Symlink to active release
├── releases/
│   ├── 20240115_120000/                  # Release directories (timestamped)
│   └── 20240114_090000/
├── shared/                               # Persistent data across releases
│   ├── storage/                          # Laravel storage (logs, cache, uploads)
│   └── .env                              # Environment configuration
└── repo/                                 # Git bare repository cache
```

## Commands

### Available Commands

| Command       | Description                                       | Usage                                                |
| ------------- | ------------------------------------------------- | ---------------------------------------------------- |
| `server:info` | Display server information (services, PHP, sites) | `deployer server:info --server=<name>`               |
| `server:logs` | View server logs (system, services, sites)        | `deployer server:logs --server=<name>`               |
| `server:run`  | Execute command on server (with restrictions)     | `deployer server:run --server=<name> --command="…"` |

### Log Sources

Available log sources for `server:logs`:

| Source                           | Description                           |
| -------------------------------- | ------------------------------------- |
| `system`                         | System journal logs                   |
| `nginx`                          | Nginx service logs                    |
| `php{version}-fpm`               | PHP-FPM logs (e.g., `php8.3-fpm`)     |
| `mysql`, `mariadb`, `postgresql` | Database logs                         |
| `redis`, `valkey`, `memcached`   | Cache service logs                    |
| `supervisor`                     | Supervisor service logs               |
| `cron`                           | Cron service logs                     |
| `{domain}`                       | Site access logs                      |
| `cron:{domain}/{script}`         | Per-script cron logs                  |
| `supervisor:{domain}/{program}`  | Per-program supervisor logs           |
| `all-sites`                      | All site access logs                  |
| `all-crons`                      | Cron service + all script logs        |
| `all-supervisors`                | Supervisor service + all program logs |

## Shell Command Safety

When using `server:run`, only execute **safe, read-only commands**. This tier is for inspection and diagnostics, not modification.

> **Important:** The `server:run` command executes via non-interactive SSH without a terminal (no PTY). **Never use interactive commands** like `less`, `top`, `htop`, `vim`, `nano`, or anything requiring keyboard input—they will hang indefinitely. Use non-interactive alternatives (e.g., `top -b -n 1` for a process snapshot).

### Safe Commands (Allowed)

| Category        | Commands                                                    |
| --------------- | ----------------------------------------------------------- |
| File inspection | `ls`, `cat`, `head`, `tail`, `find`, `grep`, `wc`           |
| Process info    | `ps`, `pgrep`, `top -b -n 1` (batch mode, single snapshot)  |
| System info     | `df`, `du`, `free`, `uptime`, `uname`, `hostname`, `whoami` |
| Network info    | `netstat`, `ss`, `curl` (GET only), `wget` (GET only), `ping`, `dig` |
| Service status  | `systemctl status`                                         |
| Logs            | `dmesg`, `last`, `journalctl`                              |
| PHP/App         | `php -v`, `php -i`, `composer show`, `npm list`            |

### Dangerous Commands (NEVER Run)

| Category         | Commands                                       | Risk               |
| ---------------- | ---------------------------------------------- | ------------------ |
| Destructive      | `rm`, `rmdir`, `dd`, `mkfs`, `fdisk`           | Data loss          |
| Permission       | `chmod`, `chown`, `chgrp`                      | Security breach    |
| Process control  | `kill`, `killall`, `reboot`, `shutdown`        | Service disruption |
| Package mgmt     | `apt`, `apt-get`, `dpkg`, `yum`                | System instability |
| Service control  | `systemctl start/stop/restart/enable/disable` | Service disruption |
| Editors          | `vim`, `vi`, `nano`                            | Interactive (hangs) |
| File modification | `mv`, `cp`, `touch`, `mkdir`, `tar`           | Data corruption    |
| Pipes to file    | `>`, `>>`, `tee`                               | Data overwrite     |
| Privilege        | `sudo`, `su`                                   | Security breach    |

## Debugging

### View Logs

```bash
# System and service logs
deployer server:logs --server=production --service=nginx,php8.3-fpm --lines=100

# Site-specific logs (access, crons, supervisors for one site)
deployer server:logs --server=production --site=example.com --lines=100
```

### Check Server Status

```bash
# View detailed server information
deployer server:info --server=production

# Check specific service status
deployer server:run --server=production --command="systemctl status nginx"
deployer server:run --server=production --command="systemctl status php8.3-fpm"

# Check disk space
deployer server:run --server=production --command="df -h"

# Check running processes
deployer server:run --server=production --command="ps aux | grep php"

# Check memory usage
deployer server:run --server=production --command="free -h"
```

### Common Diagnostic Commands

```bash
# Check release directory contents
deployer server:run --server=production --command="ls -la /home/deployer/example.com/releases"

# View current symlink target
deployer server:run --server=production --command="readlink /home/deployer/example.com/current"

# Check Laravel log
deployer server:run --server=production --command="tail -100 /home/deployer/example.com/shared/storage/logs/laravel.log"

# Check .env exists
deployer server:run --server=production --command="ls -la /home/deployer/example.com/shared/.env"

# Check PHP configuration
deployer server:run --server=production --command="php -i | grep memory_limit"

# Check open connections
deployer server:run --server=production --command="ss -tuln"

# Check failed services
deployer server:run --server=production --command="systemctl --failed"
```

### Common Issues

#### Deployment Failed

1. Check deployment scripts exist: `ls .deployer/scripts/`
2. Ensure deploy key is added to Git provider
3. Check script syntax: `bash -n .deployer/scripts/1-building.sh`
4. Review deployment logs for specific error

#### Service Not Starting

1. Check service status: `deployer server:run --server=<name> --command="systemctl status <service>"`
2. View service logs: `deployer server:logs --server=<name> --service=<service>`
3. (Recommend user restart service if needed)

#### Site Not Accessible

1. Verify DNS points to server IP
2. Check site exists: `deployer server:info --server=<name>`
3. View Nginx logs: `deployer server:logs --server=<name> --service=nginx`
4. Check HTTPS is enabled if using https:// URL

#### Cron/Supervisor Not Working

1. Check logs: `deployer server:logs --server=<name> --site=<domain>`
2. Verify scripts exist in `.deployer/crons/` or `.deployer/supervisors/`
3. Check supervisor process status: `deployer server:run --server=<name> --command="supervisorctl status"`
