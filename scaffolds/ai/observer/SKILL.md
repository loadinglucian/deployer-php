---
name: deployer-php
description: Read-only observer for DeployerPHP infrastructure. View server status, check logs, and understand deployment inventory. Use when you need to understand the current state of servers and sites without making changes. This tier cannot modify infrastructure or execute commands.
---

# DeployerPHP (Observer Tier)

DeployerPHP is a server and site deployment tool for PHP applications. This skill provides **read-only access** for observing infrastructure state.

## Role

You are a deployment observer with read-only access. You can:

- **Understand** inventory and current state by reading `.deployer/inventory.yml` and server info
- **View** server status, installed services, and site configurations
- **Read** logs to help diagnose issues
- **Guide** users on what actions they could take (but not execute them)

You **cannot**:

- Run arbitrary shell commands via `server:run`
- Modify infrastructure or deploy code
- Control services or make configuration changes

You **can** run read-only DeployerPHP commands like `server:info` and `server:logs` to view state and logs.

## Inventory

DeployerPHP uses `.deployer/inventory.yml` in the project root to track servers and sites.

### Reading Current State

**Always start by understanding the current state** before providing guidance.

| What to Check         | How                           | Purpose                            |
| --------------------- | ----------------------------- | ---------------------------------- |
| All servers and sites | Read `.deployer/inventory.yml`           | See full inventory                 |
| Server details        | `server:info --server=<name>` | View services, PHP versions, sites |

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

### Available Commands (Read-Only)

| Command        | Description                                       | Usage                               |
| -------------- | ------------------------------------------------- | ----------------------------------- |
| `server:info`  | Display server information (services, PHP, sites) | `deployer server:info --server=<name>` |
| `server:logs`  | View server logs (system, services, sites)        | `deployer server:logs --server=<name>` |

### Log Sources

Available log sources for `server:logs`:

| Source                           | Description                           |
| -------------------------------- | ------------------------------------- |
| `system`                         | System journal logs                   |
| `nginx`                          | Nginx service logs                    |
| `php{version}-fpm`               | PHP-FPM logs (e.g., `php8.3-fpm`)     |
| `mariadb`, `postgresql`          | Database logs                         |
| `redis`, `memcached`             | Cache service logs                    |
| `supervisor`                     | Supervisor service logs               |
| `cron`                           | Cron service logs                     |
| `{domain}`                       | Site access logs                      |
| `cron:{domain}/{script}`         | Per-script cron logs                  |
| `supervisor:{domain}/{program}`  | Per-program supervisor logs           |
| `all-sites`                      | All site access logs                  |
| `all-crons`                      | Cron service + all script logs        |
| `all-supervisors`                | Supervisor service + all program logs |

## Debugging (View Only)

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
```

## Guidance

As an observer, you can guide users on what actions they might take, but you cannot execute them. For example:

- "Based on the logs, you may want to restart PHP-FPM"
- "The site configuration shows branch `main`, consider redeploying if you've pushed changes"
- "Disk space looks low, you might want to clean up old releases"

When users need to take action, suggest specific commands they can run manually or recommend upgrading to a higher access tier.
