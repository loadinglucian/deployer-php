# Command Reference: Nginx

<!-- toc -->

- [nginx:start](#nginx-start)
- [nginx:stop](#nginx-stop)
- [nginx:restart](#nginx-restart)

<!-- /toc -->

Use these commands to control the Nginx service on installed servers.

<a name="nginx-start"></a>

## nginx:start

- **What it does**: Starts the Nginx service.
- **When to use it**: Recovering from downtime or bringing a newly installed server online.
- **Prerequisites**: Nginx installed and server reachable.
- **Effects on server/inventory/resources**: Starts remote service.
- **Related commands**: `nginx:stop`, `nginx:restart`, `server:logs`.
- **Failure/guardrail behavior**: Surfaces service startup errors directly.

<a name="nginx-stop"></a>

## nginx:stop

- **What it does**: Stops the Nginx service.
- **When to use it**: Controlled maintenance windows and low-level troubleshooting.
- **Prerequisites**: Nginx installed and server reachable.
- **Effects on server/inventory/resources**: Stops remote service.
- **Related commands**: `nginx:start`, `nginx:restart`.
- **Failure/guardrail behavior**: Stops with service-level errors if shutdown fails.

<a name="nginx-restart"></a>

## nginx:restart

- **What it does**: Restarts Nginx to reload service state.
- **When to use it**: After web config updates or runtime recovery actions.
- **Prerequisites**: Nginx installed and server reachable.
- **Effects on server/inventory/resources**: Restarts remote service.
- **Related commands**: `nginx:start`, `nginx:stop`, `server:logs`.
- **Failure/guardrail behavior**: Reports restart failures with actionable context.
