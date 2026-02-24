# Crons & Supervisors

<!-- toc -->

- [How It Works](#how-it-works)
- [Cron Jobs](#cron-jobs)
- [Supervisor Processes](#supervisor-processes)
- [Next Steps](#next-steps)

<!-- /toc -->

Most applications need more than request-response cycles. You probably have scheduled tasks that should run periodically (clearing caches, sending digest emails, processing queued jobs) and long-running workers that need to stay alive around the clock (queue consumers, WebSocket servers). This guide is going to walk you through setting up both using DeployerPHP.

Both follow the same inventory-driven pattern you've already seen with servers and sites: you define what you want locally, then sync those definitions to the server when you're ready. Nothing changes remotely until you explicitly tell it to.

<a name="how-it-works"></a>

## How It Works

Before diving into the commands, it helps to understand the two-step pattern that both cron and supervisor share:

1. **Define locally**: Use `cron:create` or `supervisor:create` to add definitions to your inventory. At this point, nothing happens on the server.
2. **Sync remotely**: Run the `cron:sync` or `supervisor:sync` commands to push your inventory definitions to the server. This is the moment your changes go live.

This separation is intentional. It lets you stage multiple changes (add a job, remove another, tweak a third) and apply them all at once with a single sync. If something looks wrong in your inventory before syncing, you can fix it without worrying about a half-applied state on the server.

> [!NOTE]
> You can review your inventory at any time to verify definitions before syncing.

<a name="cron-jobs"></a>

## Cron Jobs

Let's walk through adding a scheduled task to your site. Before you can create a cron job, you'll need a script for it to run. If you haven't already, run the `scaffold:scripts` command in your project directory to generate starter scripts:

```shell
deployer scaffold:scripts
```

This creates a `cron.sh` script (along with `deploy.sh` and `supervisor.sh`) in your project's `.deployer/scripts` directory. The scaffolded `cron.sh` includes framework detection for Laravel, Symfony, and CodeIgniter, so it works out of the box for most PHP applications. You're free to customize it for your needs.

### Creating a Cron

Run the `cron:create` command to add a new cron job to your inventory:

```shell
deployer cron:create
```

The command will ask for the server and site this job belongs to, the path to the script that should run, and a cron expression defining the schedule. Once you've answered the prompts, the job is saved to your local inventory. Your server hasn't changed yet.

### Syncing to the Server

To apply your cron definitions to the server, run:

```shell
deployer cron:sync
```

This writes the crontab entries for the target site's `deployer` user on the server. From this point on, your scheduled task will run on the schedule you defined.

### Removing a Cron

When you no longer need a scheduled task, remove it with `cron:delete`:

```shell
deployer cron:delete
```

The command will prompt you to select which cron job to remove from your inventory. After deleting, run the `cron:sync` command again to update the server's crontab.

> [!IMPORTANT]
> Deleting a cron definition only removes it from your local inventory. The server's crontab won't change until you run the `cron:sync` command.

<a name="supervisor-processes"></a>

## Supervisor Processes

Supervisor processes are long-running workers that need to stay alive continuously, things like queue workers, WebSocket servers, or any daemon your application relies on. Supervisord monitors these processes and automatically restarts them if they crash.

Like cron jobs, you'll need a script for your worker. If you ran `scaffold:scripts` earlier, you already have a `supervisor.sh` in your project's `.deployer/scripts` directory. If not, run it now:

```shell
deployer scaffold:scripts
```

The scaffolded `supervisor.sh` includes framework detection for Laravel, Symfony, and CodeIgniter, and uses `exec` to replace the shell process with your worker. This ensures supervisord can send signals directly to your application for graceful shutdowns.

### Creating a Supervisor

Run the `supervisor:create` command to add a new process definition:

```shell
deployer supervisor:create
```

The command will ask for the server and site this process belongs to and the path to the worker script. Like cron, this saves the definition to your local inventory without touching the server.

### Syncing to the Server

Apply your supervisor definitions with:

```shell
deployer supervisor:sync
```

This writes the supervisord configuration file on the server for your site's processes. After syncing, supervisord picks up the new configuration and starts managing your workers.

### Removing a Supervisor

Remove a process definition with `supervisor:delete`:

```shell
deployer supervisor:delete
```

Select the process to remove, then run the `supervisor:sync` command to update the server.

<a name="daemon-control"></a>

### Daemon Control

Beyond managing process definitions, you can control the supervisord daemon itself using lifecycle commands:

- **`supervisor:start`**: Start the supervisord service
- **`supervisor:stop`**: Stop the supervisord service and all managed processes
- **`supervisor:restart`**: Restart supervisord and all managed processes

These commands affect the daemon directly, which means they impact every process supervisord manages on that server, not only the ones for a specific site.

> [!IMPORTANT]
> Stopping or restarting supervisord affects all managed programs across all sites on the target server. If you have multiple sites with supervisor processes, they'll all be impacted.

<a name="next-steps"></a>

## Next Steps

With scheduled tasks running and workers humming along, next you'll probably want to know what to do if something goes wrong. For more information, see [Logs & Debugging](logs-and-debugging.md).
