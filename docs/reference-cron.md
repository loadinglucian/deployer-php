# Command Reference: Cron

<!-- toc -->

- [cron:create](#cron-create)
- [cron:sync](#cron-sync)
- [cron:delete](#cron-delete)

<!-- /toc -->

Use these commands to define scheduled site jobs in inventory and synchronize them to the remote server.

<a name="cron-create"></a>

## cron:create

- **What it does**: Creates a cron definition for a site in inventory.
- **When to use it**: Adding scheduled application tasks such as queues, schedulers, and periodic maintenance.
- **Prerequisites**: Existing site and an executable script available in your project.
- **Effects on server/inventory/resources**: Writes cron metadata to inventory.
- **Related commands**: `cron:sync`, `cron:delete`, `scaffold:scripts`.
- **Failure/guardrail behavior**: Fails on invalid schedule/script validation or duplicate definitions.

<a name="cron-sync"></a>

## cron:sync

- **What it does**: Applies inventory cron definitions to the server's crontab.
- **When to use it**: After creating, editing, or deleting cron definitions.
- **Prerequisites**: Existing server/site linkage and valid cron definitions in inventory.
- **Effects on server/inventory/resources**: Updates remote crontab entries.
- **Related commands**: `cron:create`, `cron:delete`, `server:logs`.
- **Failure/guardrail behavior**: Stops on sync or remote write failures.

<a name="cron-delete"></a>

## cron:delete

- **What it does**: Removes a cron definition from a site's inventory record.
- **When to use it**: Retiring scheduled jobs that are no longer required.
- **Prerequisites**: Existing site with at least one cron definition.
- **Effects on server/inventory/resources**: Updates inventory, then requires `cron:sync` to apply remotely.
- **Related commands**: `cron:sync`, `cron:create`.
- **Failure/guardrail behavior**: Uses confirmation prompts and blocks deletion when target jobs cannot be resolved.
