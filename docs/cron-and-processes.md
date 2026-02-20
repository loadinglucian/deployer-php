# Cron Jobs & Processes

<!-- toc -->

- [Cron Workflow](#cron-workflow)
- [Supervisor Workflow](#supervisor-workflow)
- [Operational Pattern](#operational-pattern)
- [Related References](#related-references)

<!-- /toc -->

Scheduled jobs and long-running workers are both inventory-driven in DeployerPHP. You define intent locally, then sync to the server.

<a name="cron-workflow"></a>

## Cron Workflow

Use `cron:create` and `cron:delete` to manage inventory definitions, then run `cron:sync` to apply them remotely.

Typical sequence:

1. Generate or update script files with `scaffold:scripts`.
2. Create jobs with `cron:create`.
3. Sync to server with `cron:sync`.
4. Remove jobs with `cron:delete` when no longer needed, then sync again.

<a name="supervisor-workflow"></a>

## Supervisor Workflow

Use `supervisor:create` and `supervisor:delete` for inventory definitions, then `supervisor:sync` to write server config.

Service lifecycle commands (`supervisor:start`, `supervisor:stop`, `supervisor:restart`) control the daemon itself.

<a name="operational-pattern"></a>

## Operational Pattern

For both cron and supervisor:

- Inventory changes are local until you sync.
- Sync is the boundary where remote behavior changes.
- Logs are your first validation step after sync.

> [!INFO]
> Keep script paths stable and version-controlled. This makes cron and process behavior easier to audit across deployments.

## Related References

- [Cron Reference](reference-cron.md)
- [Supervisor Reference](reference-supervisor.md)
- [Scaffold Reference](reference-scaffold.md)
