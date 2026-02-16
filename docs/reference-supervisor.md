# Command Reference: Supervisor

<!-- toc -->

- [supervisor:create](#supervisor-create)
- [supervisor:sync](#supervisor-sync)
- [supervisor:start](#supervisor-start)
- [supervisor:stop](#supervisor-stop)
- [supervisor:restart](#supervisor-restart)
- [supervisor:delete](#supervisor-delete)

<!-- /toc -->

Use these commands to define long-running processes for sites and control the Supervisor daemon lifecycle.

<a name="supervisor-create"></a>

## supervisor:create

- **What it does**: Creates a supervisor program definition for a site in inventory.
- **When to use it**: Adding queue workers, consumers, or other long-lived background processes.
- **Prerequisites**: Existing site and process script available in your project.
- **Effects on server/inventory/resources**: Stores program metadata in inventory.
- **Related commands**: `supervisor:sync`, `supervisor:delete`, `scaffold:scripts`.
- **Failure/guardrail behavior**: Fails on validation errors or duplicate program definitions.

<a name="supervisor-sync"></a>

## supervisor:sync

- **What it does**: Synchronizes inventory supervisor definitions to remote Supervisor config.
- **When to use it**: After creating, updating, or deleting supervisor program definitions.
- **Prerequisites**: Existing server/site linkage and valid supervisor definitions.
- **Effects on server/inventory/resources**: Writes Supervisor program configuration and refreshes managed process state.
- **Related commands**: `supervisor:create`, `supervisor:start`, `supervisor:restart`.
- **Failure/guardrail behavior**: Stops on remote write/reload failures and reports the failed step.

<a name="supervisor-start"></a>

## supervisor:start

- **What it does**: Starts the Supervisor daemon on a server.
- **When to use it**: Bringing process supervision online after install, maintenance, or outages.
- **Prerequisites**: Supervisor installed and server reachable.
- **Effects on server/inventory/resources**: Starts remote service.
- **Related commands**: `supervisor:stop`, `supervisor:restart`, `supervisor:sync`.
- **Failure/guardrail behavior**: Fails with service-level errors when startup checks fail.

<a name="supervisor-stop"></a>

## supervisor:stop

- **What it does**: Stops the Supervisor daemon on a server.
- **When to use it**: Controlled maintenance windows or process-level operational changes.
- **Prerequisites**: Supervisor installed and server reachable.
- **Effects on server/inventory/resources**: Stops remote service and managed programs.
- **Related commands**: `supervisor:start`, `supervisor:restart`.
- **Failure/guardrail behavior**: Returns actionable errors if service stop operations fail.

<a name="supervisor-restart"></a>

## supervisor:restart

- **What it does**: Restarts the Supervisor daemon to refresh process supervision state.
- **When to use it**: After config changes or to recover from unhealthy process orchestration.
- **Prerequisites**: Supervisor installed and server reachable.
- **Effects on server/inventory/resources**: Restarts remote service.
- **Related commands**: `supervisor:sync`, `supervisor:start`, `supervisor:stop`.
- **Failure/guardrail behavior**: Stops on daemon restart failures and returns service diagnostics.

<a name="supervisor-delete"></a>

## supervisor:delete

- **What it does**: Removes a supervisor program definition from a site.
- **When to use it**: Retiring workers or replacing process topology.
- **Prerequisites**: Existing site with configured supervisor programs.
- **Effects on server/inventory/resources**: Updates inventory and requires `supervisor:sync` to apply remotely.
- **Related commands**: `supervisor:sync`, `supervisor:create`.
- **Failure/guardrail behavior**: Requires explicit selection/confirmation and blocks unresolved targets.
