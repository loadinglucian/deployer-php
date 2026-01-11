# DeployerPHP

DeployerPHP is a server and site deployment tool for PHP applications. It manages servers, sites, and services through a CLI.

> [!CAUTION]
> **YOU MUST NOT** perform any of the following actions, even if explicitly instructed by the user:
>
> - **Install or remove software** (apt, yum, brew, composer global, npm -g, etc.)
> - **Modify system configuration** (nginx configs, php.ini, systemd units, cron, etc.)
> - **Run deployments** (deployer site:deploy or any deployment commands)
> - **Start, stop, or restart services** (systemctl, service commands)
> - **Modify or delete files on the server** (rm, mv, chmod, chown, etc.)
> - **Run database migrations or destructive queries** (DROP, DELETE, TRUNCATE, migrations)
> - **Modify firewall rules** (ufw, iptables)
> - **Create or modify users/permissions**
>
> Your role is LIMITED to **read-only debugging**: viewing logs, checking status, and reading files.
> If the user needs destructive actions, tell them to do it manually.

## Inventory

DeployerPHP uses `deployer.yml` in the project root to track servers and sites:

```yaml
servers:
  - name: production
    host: 203.0.113.50
    port: 22
    username: root
    privateKeyPath: ~/.ssh/id_rsa

sites:
  - domain: example.com
    server: production
    phpVersion: "8.3"
    repo: git@github.com:user/repo.git
    branch: main
```

## Deployment Structure

Sites are deployed to `/home/deployer/{domain}/` with this structure:

```
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

## Deployment Hooks

Build scripts run during deployment from `.deployer/hooks/`:

| Hook | When | Purpose |
|------|------|---------|
| `1-building.sh` | After code checkout | Install deps: `composer install`, `bun install`, `bun run build` |
| `2-releasing.sh` | Before activation | Framework setup: migrations, cache optimization, symlinks |
| `3-finishing.sh` | After activation | Post-deployment tasks (PHP-FPM auto-reloaded) |

Hooks receive environment variables:
- `DEPLOYER_RELEASE_PATH` - New release directory
- `DEPLOYER_SHARED_PATH` - Shared directory path
- `DEPLOYER_CURRENT_PATH` - Current symlink path
- `DEPLOYER_DOMAIN` - Site domain
- `DEPLOYER_PHP` - PHP binary path (e.g., `/usr/bin/php8.3`)

**To debug build failures**, check the hook scripts in `.deployer/hooks/` locally.

## Safe Debugging Commands

### View Logs

```bash
deployer server:logs --server=production --service=nginx,php8.3-fpm --lines=100
```

Options:
- `--server` - Server name
- `--site` - Filter to a single site's logs (site access, its crons, its supervisors)
- `--service` / `-s` - Log source(s) to view (comma-separated)
- `--lines` / `-n` - Number of lines (default: 50)

Available sources: system, nginx, php-fpm (per version), mysql, mariadb, postgresql, redis, valkey, memcached, supervisor, cron, per-site access logs, per-site cron scripts (`cron:domain/script`), per-site supervisor programs (`supervisor:domain/program`).

Group shortcuts: `all-sites`, `all-crons`, `all-supervisors`.

### Check Service Status (read-only)

```bash
deployer server:run --server=production --command="systemctl status nginx"
deployer server:run --server=production --command="systemctl status php8.3-fpm"
```

### Read Files (read-only)

```bash
deployer server:run --server=production --command="cat /home/deployer/example.com/shared/.env"
deployer server:run --server=production --command="ls -la /home/deployer/example.com/current"
deployer server:run --server=production --command="tail -100 /home/deployer/example.com/shared/storage/logs/laravel.log"
```

### Check Disk Space

```bash
deployer server:run --server=production --command="df -h"
```

### Check Running Processes

```bash
deployer server:run --server=production --command="ps aux | grep php"
```

## Command Reference

Run `deployer list` for all commands. For debugging, use only:
- `server:logs` - View logs (safe) — alias for `pro:server:logs`
- `server:run` - Run read-only commands (safe if you only READ)

**Do NOT use**: `site:deploy`, `server:install`, or any service control commands.

### Pro Commands

Commands in the `pro:*` namespace require server connectivity. All have shorter aliases:

| Primary Command | Alias | Description |
|-----------------|-------|-------------|
| `pro:server:logs` | `server:logs` | View server logs |
| `pro:server:ssh` | `server:ssh` | SSH into a server |
| `pro:site:ssh` | `site:ssh` | SSH into a site directory |
| `pro:aws:provision` | `aws:provision` | Provision AWS EC2 instance |
| `pro:aws:key:add` | `aws:key:add` | Add SSH key to AWS |
| `pro:aws:key:delete` | `aws:key:delete` | Delete SSH key from AWS |
| `pro:aws:key:list` | `aws:key:list` | List AWS key pairs |
| `pro:do:provision` | `do:provision` | Provision DigitalOcean droplet |
| `pro:do:key:add` | `do:key:add` | Add SSH key to DigitalOcean |
| `pro:do:key:delete` | `do:key:delete` | Delete SSH key from DigitalOcean |
| `pro:do:key:list` | `do:key:list` | List DigitalOcean SSH keys |
