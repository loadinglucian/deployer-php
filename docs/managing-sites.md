# Managing Sites

<!-- toc -->

- [Shared Files](#shared-files)
    - [Listing Files](#listing-files)
    - [Pushing Files](#pushing-files)
    - [Pulling Files](#pulling-files)
- [SSH Access](#ssh-access)
- [Viewing Logs](#viewing-logs)
- [Cron Jobs](#cron-jobs)
    - [Scaffolding Cron Scripts](#scaffolding-cron-scripts)
    - [Creating Cron Jobs](#creating-cron-jobs)
    - [Syncing Cron Jobs](#syncing-cron-jobs)
    - [Deleting Cron Jobs](#deleting-cron-jobs)
- [Supervisor Processes](#supervisor-processes)
    - [Scaffolding Supervisor Scripts](#scaffolding-supervisor-scripts)
    - [Creating Processes](#creating-processes)
    - [Managing Processes](#managing-processes)
    - [Syncing Processes](#syncing-processes)
    - [Deleting Processes](#deleting-processes)
- [Scaffolding](#scaffolding)
    - [Deployment Scripts](#deployment-scripts)
    - [AI Agent Skills](#ai-agent-skills)
- [Rollbacks](#rollbacks)
- [Deleting a Site](#deleting-a-site)

<!-- /toc -->

Sites are applications deployed to your servers. DeployerPHP manages the complete lifecycle from creation through deployment, including automation like cron jobs and background processes.

Sites are stored in your local inventory and linked to a server. Each site has its own Nginx configuration, PHP-FPM pool, and directory structure.

## Shared Files

Shared files persist across deployments. Common examples include `.env` files, user uploads, and configuration files. After deployments, DeployerPHP automatically symlinks items from the `shared/` directory into each release.

### Listing Files

The `site:shared:list` command shows all files and folders in a site's shared directory:

```shell
deployer site:shared:list
```

You'll be prompted to select a site. DeployerPHP connects to the server and displays the directory tree, including hidden files like `.env`.

### Pushing Files

The `site:shared:push` command uploads files to the shared directory:

```shell
deployer site:shared:push
```

You'll be prompted for the site, the local file path to upload, and the remote filename within the shared directory.

### Pulling Files

The `site:shared:pull` command downloads files from the shared directory:

```shell
deployer site:shared:pull
```

You'll be prompted for the site, the remote filename, and the local destination path.

> [!NOTE]
> The `site:shared:*` commands support single files. Create directory structures your application needs in the `1-building.sh` script as described in [Zero to Deploy](/docs/zero-to-deploy).

## SSH Access

The `site:ssh` command opens an SSH session directly in a site's directory:

```shell
deployer site:ssh
```

You'll be prompted to select a site from your inventory. The session opens in the site's root directory (`/home/deployer/sites/{domain}/`) as the `deployer` user.

## Viewing Logs

To view logs for a specific site, use the `server:logs` command with the site filter to show that site's Nginx access logs, cron logs, and supervisor logs:

```shell
deployer server:logs
```

Select your server, then choose from the available log sources. For full documentation, see [Viewing Logs](/docs/managing-servers#viewing-logs) in the Managing Servers guide.

## Cron Jobs

Cron jobs run scheduled tasks for your site. DeployerPHP manages cron scripts in your repository's `.deployer/crons/` directory and syncs them to the server.

> [!TIP]
> New to cron jobs? See [Cron Jobs](/docs/crons-and-supervisors#cron-jobs) in the Crons and Supervisors guide for a quick introduction.

### Scaffolding Cron Scripts

Run `scaffold:crons` to create example cron scripts in your repository:

```shell
deployer scaffold:crons
```

This creates `.deployer/crons/` with example scripts like `scheduler.sh` for Laravel's scheduler and `messenger.sh` for Symfony Messenger.

### Creating Cron Jobs

The `cron:create` command adds a cron job to a site:

```shell
deployer cron:create
```

You'll be prompted to select a script from `.deployer/crons/` and provide a schedule expression (e.g., `*/5 * * * *` for every 5 minutes).

### Syncing Cron Jobs

The `cron:sync` command syncs cron definitions from your inventory to the server:

```shell
deployer cron:sync
```

Run this after adding or modifying cron jobs in your inventory.

### Deleting Cron Jobs

```shell
deployer cron:delete
```

You'll be prompted to select the site and cron script to delete, with confirmation prompts for safety.

## Supervisor Processes

Supervisor manages long-running processes like queue workers, WebSocket servers, or custom daemons. DeployerPHP manages supervisor scripts in your repository's `.deployer/supervisors/` directory.

> [!TIP]
> New to supervisor? See [Supervisor Processes](/docs/crons-and-supervisors#supervisor-processes) in the Crons and Supervisors guide for a quick introduction.

### Scaffolding Supervisor Scripts

Run `scaffold:supervisors` to create example supervisor scripts:

```shell
deployer scaffold:supervisors
```

This creates `.deployer/supervisors/` with example scripts like `queue-worker.sh` for Laravel queues and `messenger.sh` for Symfony Messenger.

### Creating Processes

The `supervisor:create` command adds a supervised process:

```shell
deployer supervisor:create
```

You'll be prompted for the site, program name, script to run, and process settings like the number of instances.

### Managing Processes

The supervisor service commands operate at the server level, controlling the supervisord daemon:

```shell
deployer supervisor:start
deployer supervisor:stop
deployer supervisor:restart
```

Restarting is useful after deployments to pick up new process configurations.

### Syncing Processes

The `supervisor:sync` command syncs process definitions from your inventory to the server:

```shell
deployer supervisor:sync
```

Run this after adding or modifying supervisor processes in your inventory.

### Deleting Processes

```shell
deployer supervisor:delete
```

You'll be prompted to select the site and program to delete, with confirmation prompts for safety.

## Scaffolding

Scaffolding commands generate the `.deployer/` directory structure in your project.

### Deployment Scripts

Run `scaffold:scripts` to create deployment scripts:

```shell
deployer scaffold:scripts
```

This creates `.deployer/scripts/` with `1-building.sh`, `2-releasing.sh`, and `3-finishing.sh`. These scripts run during the deployment lifecycle as described in [Zero to Deploy](/docs/zero-to-deploy).

### AI Agent Skills

Run `scaffold:ai` to scaffold AI agent skills for DeployerPHP:

```shell
deployer scaffold:ai
```

DeployerPHP selects the AI agent using this flow: if exactly one agent directory exists, it is selected automatically; if multiple exist, you'll be prompted to choose one; if none exist, you'll be prompted to choose one to create. You'll also select a permission tier (Debugger is the default). Supported agents include Claude, Codex, Cursor, and OpenCode.

For more details on using AI agents with DeployerPHP, see [AI Automation](/docs/automation#ai-automation).

## Rollbacks

DeployerPHP follows a forward-only deployment philosophy:

```shell
deployer site:rollback
```

Rather than reverting to a previous release, this command explains why forward-only deployments are preferred:

- Rollbacks can leave databases in inconsistent states
- Forward-only encourages proper testing before deployment
- Quick fixes and redeployments are often faster than rollbacks

If you need to revert code, use Git to revert the changes and redeploy:

```shell
git revert HEAD
git push
deployer site:deploy
```

## Deleting a Site

The `site:delete` command removes a site:

```shell
deployer site:delete
```

For safety, you must type the domain to confirm, then respond to an additional Yes/No prompt.

> [!WARNING]
> This permanently deletes all site files, releases, and shared data from the server. Use the inventory-only mode if you only want to remove the site from your local inventory without affecting the server.

If remote deletion fails (for example, due to connection issues), you'll be prompted whether to remove the site from inventory anyway.
