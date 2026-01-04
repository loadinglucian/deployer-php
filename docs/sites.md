# Site Management

- [Introduction](#introduction)
- [Creating a Site](#creating-a-site)
- [Deploying a Site](#deploying-a-site)
    - [Deployment Hooks](#deployment-hooks)
    - [Release Management](#release-management)
- [Enabling HTTPS](#enabling-https)
- [Shared Files](#shared-files)
    - [Pushing Files](#pushing-files)
    - [Pulling Files](#pulling-files)
- [Viewing Logs](#viewing-logs)
- [SSH Access](#ssh-access)
- [Rollbacks](#rollbacks)
- [Deleting a Site](#deleting-a-site)
- [Cron Jobs](#cron-jobs)
    - [Creating Cron Jobs](#creating-cron-jobs)
    - [Syncing Cron Jobs](#syncing-cron-jobs)
    - [Viewing Cron Logs](#viewing-cron-logs)
    - [Deleting Cron Jobs](#deleting-cron-jobs)
- [Supervisor Processes](#supervisor-processes)
    - [Creating Processes](#creating-processes)
    - [Managing Processes](#managing-processes)
    - [Syncing Processes](#syncing-processes)
    - [Viewing Supervisor Logs](#viewing-supervisor-logs)
    - [Deleting Processes](#deleting-processes)
- [Scaffolding](#scaffolding)
    - [Scaffolding Hooks](#scaffolding-hooks)
    - [Scaffolding Crons](#scaffolding-crons)
    - [Scaffolding Supervisors](#scaffolding-supervisors)

<a name="introduction"></a>

## Introduction

Sites are applications deployed to your servers. DeployerPHP manages the complete lifecycle from creation through deployment, including automation like cron jobs and background processes.

Sites are stored in your local inventory and linked to a server. Each site has its own Nginx configuration, PHP-FPM pool, and directory structure.

<a name="creating-a-site"></a>

## Creating a Site

The `site:create` command sets up a new site on a server:

```bash
deployer site:create
```

You'll be prompted for:

| Option          | Description                     |
| --------------- | ------------------------------- |
| `--server`      | Server from your inventory      |
| `--domain`      | Site domain (e.g., example.com) |
| `--php-version` | PHP version for this site       |
| `--www-mode`    | WWW handling mode               |

WWW handling options:

- **redirect-to-root** - Redirect www to non-www
- **redirect-to-www** - Redirect non-www to www

For automation:

```bash
deployer site:create \
    --server=production \
    --domain=example.com \
    --php-version=8.3 \
    --www-mode=redirect-to-root
```

This creates the directory structure at `/home/deployer/sites/example.com/`:

```
example.com/
├── current -> releases/20240115120000
├── releases/
│   └── 20240115120000/
├── shared/
│   └── .env
└── .dep/
```

<a name="deploying-a-site"></a>

## Deploying a Site

The `site:deploy` command deploys your application from a Git repository:

```bash
deployer site:deploy
```

Options:

| Option            | Description                             |
| ----------------- | --------------------------------------- |
| `--domain`        | Site domain                             |
| `--repo`          | Git repository URL                      |
| `--branch`        | Branch to deploy                        |
| `--keep-releases` | Number of releases to keep (default: 5) |
| `--yes`, `-y`     | Skip confirmation prompt                |

Example:

```bash
deployer site:deploy \
    --domain=example.com \
    --repo=git@github.com:user/app.git \
    --branch=main
```

<a name="deployment-hooks"></a>

### Deployment Hooks

DeployerPHP runs deployment hooks from your repository's `.deployer/hooks/` directory:

| Hook             | Purpose                            | Example Tasks                                 |
| ---------------- | ---------------------------------- | --------------------------------------------- |
| `1-building.sh`  | Install dependencies, build assets | composer install, npm run build               |
| `2-releasing.sh` | Prepare the release                | php artisan migrate, php artisan config:cache |
| `3-finishing.sh` | Post-release tasks                 | php artisan queue:restart                     |

Example `1-building.sh` for Laravel:

```bash
#!/bin/bash
set -e

composer install --no-dev --optimize-autoloader
bun install
bun run build
```

Example `2-releasing.sh`:

```bash
#!/bin/bash
set -e

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> [!NOTE]
> Hooks run in the release directory with the `deployer` user. Use `set -e` to stop deployment on errors.

<a name="release-management"></a>

### Release Management

Each deployment creates a new release directory with a timestamp. The `current` symlink points to the active release. By default, DeployerPHP keeps the 5 most recent releases.

The `shared/` directory contains files that persist across releases (like `.env`). These are symlinked into each release.

<a name="enabling-https"></a>

## Enabling HTTPS

The `site:https` command installs an SSL certificate using Certbot:

```bash
deployer site:https --domain=example.com
```

This:

1. Installs Certbot if not present
2. Obtains a Let's Encrypt certificate
3. Configures Nginx for HTTPS
4. Sets up automatic certificate renewal

> [!NOTE]
> Your domain's DNS must point to your server before running this command.

<a name="shared-files"></a>

## Shared Files

Shared files persist across deployments. Common examples include `.env` files, user uploads, and configuration files.

<a name="pushing-files"></a>

### Pushing Files

The `site:shared:push` command uploads files to the shared directory:

```bash
deployer site:shared:push --domain=example.com
```

Options:

| Option     | Description                           |
| ---------- | ------------------------------------- |
| `--domain` | Site domain                           |
| `--local`  | Local file path to upload             |
| `--remote` | Remote filename (relative to shared/) |

For automation:

```bash
deployer site:shared:push \
    --domain=example.com \
    --local=.env.production \
    --remote=.env
```

<a name="pulling-files"></a>

### Pulling Files

The `site:shared:pull` command downloads files from the shared directory:

```bash
deployer site:shared:pull --domain=example.com
```

Options:

| Option     | Description                           |
| ---------- | ------------------------------------- |
| `--domain` | Site domain                           |
| `--remote` | Remote filename (relative to shared/) |
| `--local`  | Local destination file path           |
| `--yes`    | Skip overwrite confirmation           |

For automation:

```bash
deployer site:shared:pull \
    --domain=example.com \
    --remote=.env \
    --local=.env.backup \
    --yes
```

<a name="viewing-logs"></a>

## Viewing Logs

The `site:logs` command displays site-specific logs:

```bash
deployer site:logs --domain=example.com
```

Options:

| Option      | Description                                                      |
| ----------- | ---------------------------------------------------------------- |
| `--domain`  | Site domain                                                      |
| `--lines`   | Number of lines to retrieve (default: 50)                        |
| `--service` | Service(s) to view (comma-separated: access, crons, supervisors) |

This shows:

- Nginx access logs for the domain
- Cron job output (if crons configured)
- Supervisor process output (if supervisors configured)

For automation:

```bash
deployer site:logs \
    --domain=example.com \
    --lines=100 \
    --service=access,crons
```

<a name="ssh-access"></a>

## SSH Access

The `site:ssh` command opens an SSH session in the site's directory:

```bash
deployer site:ssh --domain=example.com
```

You'll be logged in as the `deployer` user in `/home/deployer/sites/example.com/current/`.

<a name="rollbacks"></a>

## Rollbacks

DeployerPHP follows a forward-only deployment philosophy:

```bash
deployer site:rollback
```

Rather than reverting to a previous release, this command explains why forward-only deployments are preferred:

- Rollbacks can leave databases in inconsistent states
- Forward-only encourages proper testing before deployment
- Quick fixes and redeployments are often faster than rollbacks

If you need to revert code, revert in Git and redeploy.

<a name="deleting-a-site"></a>

## Deleting a Site

The `site:delete` command removes a site:

```bash
deployer site:delete --domain=example.com
```

Safety features:

1. **Type-to-confirm** - You must type the domain to confirm
2. **Double confirmation** - An additional Yes/No prompt

Options:

| Option             | Description                      |
| ------------------ | -------------------------------- |
| `--domain`         | Site domain to delete            |
| `--force`, `-f`    | Skip type-to-confirm             |
| `--yes`, `-y`      | Skip Yes/No confirmation         |
| `--inventory-only` | Only remove from local inventory |

> [!WARNING]
> This permanently deletes all site files, releases, and shared data from the server.

<a name="cron-jobs"></a>

## Cron Jobs

Cron jobs run scheduled tasks for your site. DeployerPHP manages cron scripts in your repository's `.deployer/crons/` directory and syncs them to the server.

<a name="creating-cron-jobs"></a>

### Creating Cron Jobs

The `cron:create` command adds a cron job to a site:

```bash
deployer cron:create --domain=example.com
```

Options:

| Option       | Description                                    |
| ------------ | ---------------------------------------------- |
| `--domain`   | Site domain                                    |
| `--script`   | Cron script path within `.deployer/crons/`     |
| `--schedule` | Cron schedule expression (e.g., `*/5 * * * *`) |

You'll be prompted to select a script from `.deployer/crons/` and provide a schedule.

For automation:

```bash
deployer cron:create \
    --domain=example.com \
    --script=scheduler.sh \
    --schedule="* * * * *"
```

> [!NOTE]
> Run `scaffold:crons` to create example cron scripts in your repository.

<a name="syncing-cron-jobs"></a>

### Syncing Cron Jobs

The `cron:sync` command syncs cron definitions from inventory to the server:

```bash
deployer cron:sync --domain=example.com
```

<a name="viewing-cron-logs"></a>

### Viewing Cron Logs

The `cron:logs` command displays cron service and script logs for all sites on a server:

```bash
deployer cron:logs --server=production --lines=100
```

<a name="deleting-cron-jobs"></a>

### Deleting Cron Jobs

```bash
deployer cron:delete --domain=example.com --script=cleanup.sh
```

Options:

| Option          | Description                            |
| --------------- | -------------------------------------- |
| `--domain`      | Site domain                            |
| `--script`      | Cron script to delete                  |
| `--force`, `-f` | Skip typing the script name to confirm |
| `--yes`, `-y`   | Skip Yes/No confirmation               |

<a name="supervisor-processes"></a>

## Supervisor Processes

Supervisor manages long-running processes like queue workers, WebSocket servers, or custom daemons. DeployerPHP manages supervisor scripts in your repository's `.deployer/supervisors/` directory.

<a name="creating-processes"></a>

### Creating Processes

The `supervisor:create` command adds a supervised process:

```bash
deployer supervisor:create --domain=example.com
```

Options:

| Option           | Description                                |
| ---------------- | ------------------------------------------ |
| `--domain`       | Site domain                                |
| `--program`      | Process name identifier                    |
| `--script`       | Script in `.deployer/supervisors/`         |
| `--autostart`    | Start on supervisord start (default: true) |
| `--autorestart`  | Restart on exit (default: true)            |
| `--stopwaitsecs` | Seconds to wait for stop (default: 3600)   |
| `--numprocs`     | Number of process instances (default: 1)   |

For automation:

```bash
deployer supervisor:create \
    --domain=example.com \
    --program=queue-worker \
    --script=queue.sh \
    --numprocs=2
```

> [!NOTE]
> Run `scaffold:supervisors` to create example supervisor scripts in your repository.

<a name="managing-processes"></a>

### Managing Processes

The supervisor service commands operate at the server level, controlling the supervisord daemon:

```bash
# Start supervisord service
deployer supervisor:start --server=production

# Stop supervisord service
deployer supervisor:stop --server=production

# Restart supervisord service (useful after deployments)
deployer supervisor:restart --server=production
```

<a name="syncing-processes"></a>

### Syncing Processes

The `supervisor:sync` command syncs process definitions from inventory to the server:

```bash
deployer supervisor:sync --domain=example.com
```

<a name="viewing-supervisor-logs"></a>

### Viewing Supervisor Logs

The `supervisor:logs` command displays supervisor service and program logs for all sites on a server:

```bash
deployer supervisor:logs --server=production --lines=100
```

<a name="deleting-processes"></a>

### Deleting Processes

```bash
deployer supervisor:delete --domain=example.com --program=queue-worker
```

Options:

| Option          | Description                             |
| --------------- | --------------------------------------- |
| `--domain`      | Site domain                             |
| `--program`     | Supervisor program to delete            |
| `--force`, `-f` | Skip typing the program name to confirm |
| `--yes`, `-y`   | Skip Yes/No confirmation                |

<a name="scaffolding"></a>

## Scaffolding

Scaffolding commands generate the `.deployer/` directory structure in your project. All scaffold commands accept a `--destination` option to specify the project root directory (defaults to current directory).

<a name="scaffolding-hooks"></a>

### Scaffolding Hooks

```bash
deployer scaffold:hooks
```

This creates:

```
.deployer/
└── hooks/
    ├── 1-building.sh
    ├── 2-releasing.sh
    └── 3-finishing.sh
```

Templates are pre-filled for common PHP/Laravel workflows.

<a name="scaffolding-crons"></a>

### Scaffolding Crons

```bash
deployer scaffold:crons
```

This creates `.deployer/crons/` with example cron scripts:

```
.deployer/
└── crons/
    ├── messenger.sh
    └── scheduler.sh
```

<a name="scaffolding-supervisors"></a>

### Scaffolding Supervisors

```bash
deployer scaffold:supervisors
```

This creates `.deployer/supervisors/` with example supervisor scripts:

```
.deployer/
└── supervisors/
    ├── messenger.sh
    └── queue-worker.sh
```
