# Crons and Supervisors

<!-- toc -->

- [Cron Jobs](#cron-jobs)
  - [Scaffolding Cron Scripts](#scaffolding-cron-scripts)
  - [Creating a Cron Job](#creating-a-cron-job)
  - [Syncing Cron Jobs](#syncing-cron-jobs)
- [Supervisor Processes](#supervisor-processes)
  - [Scaffolding Supervisor Scripts](#scaffolding-supervisor-scripts)
  - [Creating a Supervised Process](#creating-a-supervised-process)
  - [Syncing Supervisor Processes](#syncing-supervisor-processes)

<!-- /toc -->

After deploying your application, you'll likely need automation: scheduled tasks that run periodically and long-running processes that stay alive. DeployerPHP provides commands for both patterns, making it easy to set up Laravel schedulers, Symfony Messenger consumers, queue workers, and any other background processes your application needs.

## Cron Jobs

Many applications need to run tasks on a schedule, like sending queued emails, generating reports, or cleaning up temporary files. Laravel has a built-in scheduler, and Symfony uses Messenger for similar purposes. DeployerPHP makes it easy to configure these scheduled tasks.

### Scaffolding Cron Scripts

Run the `scaffold:crons` command in your project directory to generate example cron scripts:

```shell
deployer scaffold:crons
```

This creates a `.deployer/crons/` directory with example scripts for Laravel and Symfony. Each script uses environment variables that DeployerPHP provides automatically, so they work correctly with your deployment structure.

### Creating a Cron Job

With your scripts in place and your site deployed, run `cron:create` to configure a scheduled task:

```shell
deployer cron:create
```

DeployerPHP will prompt you for:

- **Site** - The site to add the cron job to
- **Script** - Select from the scripts in your `.deployer/crons/` directory
- **Schedule** - A cron expression like `* * * * *` (every minute) or `0 * * * *` (hourly)

### Syncing Cron Jobs

After creating cron jobs, sync them to the server:

```shell
deployer cron:sync
```

This updates the server's crontab with your scheduled tasks. Run `cron:sync` whenever you add, modify, or remove cron jobs in your inventory.

For managing cron jobs (viewing, deleting, logs), see [Cron Jobs](/docs/managing-sites#cron-jobs) in the Managing Sites guide.

## Supervisor Processes

Some tasks need to run continuously rather than on a schedule, like queue workers processing jobs or WebSocket servers maintaining connections. Supervisor keeps these processes running and restarts them if they crash.

### Scaffolding Supervisor Scripts

Run the `scaffold:supervisors` command in your project directory:

```shell
deployer scaffold:supervisors
```

This creates a `.deployer/supervisors/` directory with example scripts for Laravel queue workers and Symfony Messenger consumers.

### Creating a Supervised Process

With your scripts ready and your site deployed, run `supervisor:create`:

```shell
deployer supervisor:create
```

DeployerPHP will prompt you for:

- **Site** - The site to add the process to
- **Script** - Select from your `.deployer/supervisors/` directory
- **Program name** - A unique identifier for this process (e.g., "queue-worker")
- **Process settings** - Options like autostart, autorestart, and number of instances

### Syncing Supervisor Processes

After creating processes, sync them to the server:

```shell
deployer supervisor:sync
```

This writes supervisor configuration files and reloads the daemon. Run `supervisor:sync` whenever you add, modify, or remove supervisor processes.

For managing processes (start, stop, restart, delete), see [Supervisor Processes](/docs/managing-sites#supervisor-processes) in the Managing Sites guide.
