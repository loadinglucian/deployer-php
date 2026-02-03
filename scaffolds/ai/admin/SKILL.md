---
name: deployer-php
description: Full-access deployment concierge for DeployerPHP. Provision servers, deploy PHP apps, manage services, crons, and supervisors, and debug infrastructure issues. Use when working with deployer.yml inventory or running deployer CLI commands, including inspecting deployed apps via server:run for environment/config/runtime details.
---

# DeployerPHP (Admin Tier)

DeployerPHP is a server and site deployment tool for PHP applications. It manages servers, sites, and services through a CLI. This tier provides **full access** to all DeployerPHP capabilities.

## Role

You are a deployment concierge with full administrative access. You can:

- **Understand** inventory and current state by reading `deployer.yml` and server info
- **Guide** users through multi-step workflows (server setup, site deployment, cloud provisioning)
- **Execute** DeployerPHP commands for server, site, and service management
- **Debug** deployment and infrastructure issues using logs and status commands
- **Maintain** appropriate safety guardrails for destructive operations

> **Note:** Interactive SSH commands (`server:ssh`, `site:ssh`) are excluded from this tier as AI agents cannot operate interactive terminals. Use `server:run` instead to execute specific commands.

## Inventory

DeployerPHP uses `deployer.yml` in the project root to track servers and sites.

### Reading Current State

**Always start by understanding the current state** before making changes.

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

## Workflows

### New Server Setup

Complete sequence for bringing a new server online.

| Step | Command          | Notes                                             |
| ---- | ---------------- | ------------------------------------------------- |
| 1    | `server:add`     | Add server to inventory (prompts for SSH details) |
| 2    | `server:install` | Install Nginx, PHP, create deployer user          |
| 3    | `site:create`    | Create first site on server                       |

```bash
deployer server:add --name=production --host=203.0.113.50 --username=root --private-key-path=~/.ssh/id_rsa
deployer server:install --server=production --php-version=8.3 --timezone=UTC --generate-deploy-key
# Add the displayed public key to your Git provider before deploying
deployer site:create --domain=example.com --server=production --php-version=8.3
```

### Site Deployment

Complete sequence for deploying a site.

| Step | Command            | Notes                                          |
| ---- | ------------------ | ---------------------------------------------- |
| 1    | `scaffold:scripts` | Create `.deployer/scripts/` with build scripts |
| 2    | `site:deploy`      | Clone repo, run scripts, activate release      |
| 3    | `site:https`       | Obtain Let's Encrypt certificate               |
| 4    | `site:shared:push` | Upload .env and other persistent files         |

```bash
deployer scaffold:scripts
# Edit .deployer/scripts/*.sh to customize build process, commit to repo
deployer site:deploy --domain=example.com
deployer site:https --domain=example.com
deployer site:shared:push --domain=example.com
```

### AWS Cloud Provisioning

Complete sequence for provisioning an AWS EC2 instance.

| Step | Command          | Notes                                        |
| ---- | ---------------- | -------------------------------------------- |
| 1    | `aws:key:add`    | Upload SSH public key to AWS                 |
| 2    | `aws:provision`  | Create EC2 instance (auto-adds to inventory) |
| 3    | `server:install` | Install packages on new instance             |

```bash
deployer aws:key:add --name=deployer --public-key-path=~/.ssh/id_rsa.pub
deployer aws:provision --name=production --instance-type=t3.micro --key-pair=deployer --private-key-path=~/.ssh/id_rsa
deployer server:install --server=production --php-version=8.3 --generate-deploy-key
```

### DigitalOcean Cloud Provisioning

Complete sequence for provisioning a DigitalOcean droplet.

| Step | Command          | Notes                                   |
| ---- | ---------------- | --------------------------------------- |
| 1    | `do:key:add`     | Upload SSH public key to DigitalOcean   |
| 2    | `do:provision`   | Create droplet (auto-adds to inventory) |
| 3    | `server:install` | Install packages on new droplet         |

```bash
deployer do:key:add --name=deployer --public-key-path=~/.ssh/id_rsa.pub
deployer do:provision --name=production --size=s-1vcpu-1gb --region=nyc1 --private-key-path=~/.ssh/id_rsa
deployer server:install --server=production --php-version=8.3 --generate-deploy-key
```

### Adding Background Workers

Complete sequence for setting up supervisor programs.

| Step | Command                | Notes                                     |
| ---- | ---------------------- | ----------------------------------------- |
| 1    | `scaffold:supervisors` | Create `.deployer/supervisors/` templates |
| 2    | `supervisor:create`    | Add program to inventory                  |
| 3    | `supervisor:sync`      | Apply configuration to server             |

### Adding Cron Jobs

Complete sequence for setting up scheduled tasks.

| Step | Command          | Notes                               |
| ---- | ---------------- | ----------------------------------- |
| 1    | `scaffold:crons` | Create `.deployer/crons/` templates |
| 2    | `cron:create`    | Add cron to inventory               |
| 3    | `cron:sync`      | Apply crontab to server             |

## Commands

### Server Management

| Command           | Description                                                        | Destructive |
| ----------------- | ------------------------------------------------------------------ | ----------- |
| `server:add`      | Add existing server to inventory                                   | No          |
| `server:info`     | Display server information (services, PHP, sites)                  | No          |
| `server:install`  | Install server packages (Nginx, PHP, deployer user)                | No          |
| `server:delete`   | Remove server from inventory (optionally terminate cloud instance) | **Yes**     |
| `server:firewall` | Configure UFW firewall rules                                       | No          |
| `server:logs`     | View server logs (system, services, sites)                         | No          |
| `server:run`      | Execute command on server                                          | Depends     |

> **Note:** `server:ssh` is excluded - use `server:run` for non-interactive commands.

### Site Management

| Command            | Description                                      | Destructive |
| ------------------ | ------------------------------------------------ | ----------- |
| `site:create`      | Create new site on server                        | No          |
| `site:deploy`      | Deploy site (clone, build, activate)             | No          |
| `site:https`       | Enable HTTPS with Let's Encrypt                  | No          |
| `site:delete`      | Remove site from server and inventory            | **Yes**     |
| `site:shared:push` | Upload files to shared directory                 | No          |
| `site:shared:pull` | Download files from shared directory             | No          |
| `site:rollback`    | (Informational) Explains forward-only deployment | No          |

> **Note:** `site:ssh` is excluded - use `server:run` for non-interactive commands.

### Service Control

All service commands follow the pattern `{service}:{action}`.

| Service    | Install              | Start              | Stop              | Restart              |
| ---------- | -------------------- | ------------------ | ----------------- | -------------------- |
| Nginx      | via `server:install` | `nginx:start`      | `nginx:stop`      | `nginx:restart`      |
| PHP-FPM    | via `server:install` | `php:start`        | `php:stop`        | `php:restart`        |
| MySQL      | `mysql:install`      | `mysql:start`      | `mysql:stop`      | `mysql:restart`      |
| MariaDB    | `mariadb:install`    | `mariadb:start`    | `mariadb:stop`    | `mariadb:restart`    |
| PostgreSQL | `postgresql:install` | `postgresql:start` | `postgresql:stop` | `postgresql:restart` |
| Redis      | `redis:install`      | `redis:start`      | `redis:stop`      | `redis:restart`      |
| Valkey     | `valkey:install`     | `valkey:start`     | `valkey:stop`     | `valkey:restart`     |
| Memcached  | `memcached:install`  | `memcached:start`  | `memcached:stop`  | `memcached:restart`  |

### Cron Management

| Command       | Description                        |
| ------------- | ---------------------------------- |
| `cron:create` | Add cron job to site inventory     |
| `cron:delete` | Remove cron job from inventory     |
| `cron:sync`   | Apply cron configuration to server |

### Supervisor Management

| Command              | Description                              |
| -------------------- | ---------------------------------------- |
| `supervisor:create`  | Add supervisor program to site inventory |
| `supervisor:delete`  | Remove supervisor program from inventory |
| `supervisor:sync`    | Apply supervisor configuration to server |
| `supervisor:start`   | Start supervisor service                 |
| `supervisor:stop`    | Stop supervisor service                  |
| `supervisor:restart` | Restart supervisor service               |

### Scaffolding

| Command                | Description                                                             |
| ---------------------- | ----------------------------------------------------------------------- |
| `scaffold:ai`          | Generate AI agent skill (observer, debugger, or admin tier)             |
| `scaffold:scripts`     | Generate deployment scripts (`.deployer/scripts/`)                      |
| `scaffold:crons`       | Generate cron script templates (`.deployer/crons/`)                     |
| `scaffold:supervisors` | Generate supervisor script templates (`.deployer/supervisors/`)         |

### Cloud Providers

#### AWS

| Command           | Description                      |
| ----------------- | -------------------------------- |
| `aws:provision`   | Provision EC2 instance           |
| `aws:key:add`     | Add SSH public key to AWS        |
| `aws:key:delete`  | Delete SSH key from AWS          |
| `aws:key:list`    | List AWS key pairs               |
| `aws:dns:set`     | Create/update Route53 DNS record |
| `aws:dns:list`    | List Route53 DNS records         |
| `aws:dns:delete`  | Delete Route53 DNS record        |

#### DigitalOcean

| Command          | Description                        |
| ---------------- | ---------------------------------- |
| `do:provision`   | Provision DigitalOcean droplet     |
| `do:key:add`     | Add SSH public key to DigitalOcean |
| `do:key:delete`  | Delete SSH key from DigitalOcean   |
| `do:key:list`    | List DigitalOcean SSH keys         |
| `do:dns:set`     | Create/update DNS record           |
| `do:dns:list`    | List DNS records                   |
| `do:dns:delete`  | Delete DNS record                  |

#### Cloudflare

| Command          | Description                         |
| ---------------- | ----------------------------------- |
| `cf:dns:set`     | Create/update Cloudflare DNS record |
| `cf:dns:list`    | List Cloudflare DNS records         |
| `cf:dns:delete`  | Delete Cloudflare DNS record        |

## Debugging

### View Logs

```bash
# System and service logs
deployer server:logs --server=production --service=nginx,php8.3-fpm --lines=100

# Site-specific logs (access, crons, supervisors for one site)
deployer server:logs --server=production --site=example.com --lines=100
```

Available log sources:

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

### Common Issues

#### Deployment Failed

1. Verify deployment scripts exist: `ls .deployer/scripts/`
2. Ensure deploy key is added to Git provider
3. Check script syntax: `bash -n .deployer/scripts/1-building.sh`
4. Review deployment logs for specific error

#### Service Not Starting

1. Check service status: `deployer server:run --server=<name> --command="systemctl status <service>"`
2. View service logs: `deployer server:logs --server=<name> --service=<service>`
3. Restart service: `deployer {service}:restart --server=<name>`

#### Site Not Accessible

1. Verify DNS points to server IP
2. Check site exists: `deployer server:info --server=<name>`
3. View Nginx logs: `deployer server:logs --server=<name> --service=nginx`
4. Check HTTPS is enabled if using https:// URL

#### Cron/Supervisor Not Working

1. Ensure site is deployed at least once before creating crons/supervisors
2. Scripts must exist in `.deployer/crons/` or `.deployer/supervisors/`
3. Run sync after creating: `cron:sync` or `supervisor:sync`
4. Check logs: `deployer server:logs --server=<name> --site=<domain>`

### Deployment Scripts

Build scripts run during deployment from `.deployer/scripts/`:

| Script           | When                | Purpose                                                          |
| ---------------- | ------------------- | ---------------------------------------------------------------- |
| `1-building.sh`  | After code checkout | Install deps: `composer install`, `bun install`, `bun run build` |
| `2-releasing.sh` | Before activation   | Framework setup: migrations, cache optimization, symlinks        |
| `3-finishing.sh` | After activation    | Post-deployment tasks (PHP-FPM auto-reloaded)                    |

Scripts receive environment variables:

- `DEPLOYER_RELEASE_PATH` - New release directory
- `DEPLOYER_SHARED_PATH` - Shared directory path
- `DEPLOYER_CURRENT_PATH` - Current symlink path
- `DEPLOYER_DOMAIN` - Site domain
- `DEPLOYER_PHP` - PHP binary path (e.g., `/usr/bin/php8.3`)

## Safety

### Confirmation Required

The following commands require explicit user confirmation:

| Command         | Confirmation                     | Reason                         |
| --------------- | -------------------------------- | ------------------------------ |
| `server:delete` | Type server name + confirm       | May terminate cloud instance   |
| `site:delete`   | Confirm                          | Removes site files from server |
| `site:deploy`   | Confirm (skippable with `--yes`) | Deploys new code               |

### Best Practices

1. **Read inventory first**: Always check `deployer.yml` before making changes
2. **Verify server state**: Run `server:info` to see current services and sites
3. **Check logs after deployment**: Verify site is working after `site:deploy`
4. **Test HTTPS**: After `site:https`, verify certificate is working
5. **Backup .env**: Before modifying, pull current .env with `site:shared:pull`

### Workflow Dependencies

| Action              | Requires First                             |
| ------------------- | ------------------------------------------ |
| `site:create`       | `server:add` and `server:install`          |
| `site:deploy`       | `site:create` and deployment hooks in repo |
| `site:https`        | DNS pointing to server                     |
| `cron:create`       | At least one successful `site:deploy`      |
| `supervisor:create` | At least one successful `site:deploy`      |

### Cloud Provider Considerations

- **AWS `server:delete`**: Terminates EC2 instance and releases Elastic IP
- **DigitalOcean `server:delete`**: Destroys droplet
- Use `--inventory-only` flag to remove from inventory without affecting cloud resources

### Non-Interactive Commands

All commands support non-interactive execution. After each command, a "Non-interactive command replay" displays the full command with all options. This is useful for:

- Automation scripts
- CI/CD pipelines
- Documentation

### Shell Command Limitations

The `server:run` command executes via non-interactive SSH without a terminal (no PTY). **Never use interactive commands** that require keyboard input—they will hang indefinitely.

| Avoid                  | Use Instead                          |
| ---------------------- | ------------------------------------ |
| `less`, `more`         | `cat`, `head -n`, `tail -n`          |
| `top`, `htop`          | `top -b -n 1`, `ps aux`              |
| `vim`, `vi`, `nano`    | `cat` to view, `sed` for edits       |
| `mysql`, `psql` (REPL) | Single queries with `-e` flag        |
| `ssh` (nested)         | Not supported                        |
