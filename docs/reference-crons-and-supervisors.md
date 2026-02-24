# Crons & Supervisors Reference

<!-- toc -->

- [At a Glance](#at-a-glance)
    - [Cron Commands](#cron-commands)
    - [Supervisor Commands](#supervisor-commands)
- [Inventory vs Remote Sync](#inventory-vs-remote-sync)
- [Cron Details](#cron-details)
    - [Creating a Cron Job](#creating-a-cron-job)
    - [Deleting a Cron Job](#deleting-a-cron-job)
- [Supervisor Details](#supervisor-details)
    - [Creating a Supervisor Program](#creating-a-supervisor-program)
    - [Deleting a Supervisor Program](#deleting-a-supervisor-program)
    - [Lifecycle Control](#lifecycle-control)

<!-- /toc -->

Use `cron:*` commands to manage scheduled jobs and `supervisor:*` commands to define and operate long-running site processes.

<a name="at-a-glance"></a>

## At a Glance

<a name="cron-commands"></a>

### Cron Commands

| Command       | Use it when you need to...                     |
| ------------- | ---------------------------------------------- |
| `cron:create` | add a scheduled job definition to a site       |
| `cron:sync`   | apply inventory cron definitions to the server |
| `cron:delete` | remove a scheduled job definition from a site  |

<a name="supervisor-commands"></a>

### Supervisor Commands

| Command              | Use it when you need to...                     |
| -------------------- | ---------------------------------------------- |
| `supervisor:create`  | add a process definition for a site            |
| `supervisor:sync`    | write inventory definitions to server config   |
| `supervisor:start`   | start the supervisord service                  |
| `supervisor:stop`    | stop the supervisord service                   |
| `supervisor:restart` | restart supervisord after changes or incidents |
| `supervisor:delete`  | remove a process definition from a site        |

<a name="inventory-vs-remote-sync"></a>

## Inventory vs Remote Sync

Both crons and supervisors follow the same two-phase pattern: `create` and `delete` update local inventory state, while `sync` is the command that applies those definitions to the server. This split lets you stage changes safely before pushing them to production.

Your site must have at least one successful deployment before you can create crons or supervisors, because scripts are validated against the deployed repository.

<a name="cron-details"></a>

## Cron Details

<a name="creating-a-cron-job"></a>

### Creating a Cron Job

`cron:create` prompts you for a script path and a cron schedule expression. Before saving, it validates that:

- The script exists in the site's remote Git repository
- No duplicate cron with the same script is already configured for the site
- The schedule expression is a valid five-field cron format (minute, hour, day, month, weekday), including support for wildcards, ranges, lists, steps, and month/weekday name aliases

After creation, run `cron:sync` to apply the definition to the server.

<a name="deleting-a-cron-job"></a>

### Deleting a Cron Job

`cron:delete` requires you to type the cron identifier to confirm deletion. After removing the definition from inventory, run `cron:sync` to update the server.

<a name="supervisor-details"></a>

## Supervisor Details

<a name="creating-a-supervisor-program"></a>

### Creating a Supervisor Program

`supervisor:create` prompts you for a script path and a program name, then collects configuration parameters:

- **Autostart** controls whether the program starts when supervisord starts (default: yes)
- **Autorestart** controls whether the program restarts on exit (default: yes)
- **Stop wait seconds** sets how long to wait for a graceful stop (default: 3600)
- **Number of processes** sets how many instances to run (default: 1)

Before saving, it validates that the script exists in the site's remote Git repository and that neither the script path nor the program name is already configured for the site.

After creation, run `supervisor:sync` to apply the definition to the server.

<a name="deleting-a-supervisor-program"></a>

### Deleting a Supervisor Program

`supervisor:delete` requires you to type the program identifier to confirm deletion. After removing the definition from inventory, run `supervisor:sync` to update the server.

<a name="lifecycle-control"></a>

### Lifecycle Control

Use `supervisor:start`, `supervisor:stop`, and `supervisor:restart` for daemon lifecycle control. These operate on the supervisord service itself, which manages all configured programs on the server.

> [!NOTE]
> Stopping or restarting Supervisor affects every managed program on the server, not only the ones for a specific site.
