# Command Reference: Redis

<!-- toc -->

- [redis:install](#redis-install)
- [redis:start](#redis-start)
- [redis:stop](#redis-stop)
- [redis:restart](#redis-restart)

<!-- /toc -->

Use these commands to install and operate Redis on managed servers.

<a name="redis-install"></a>

## redis:install

- **What it does**: Installs Redis and configures authentication for local-only secure access.
- **When to use it**: Adding Redis for cache, queue, or ephemeral data workloads.
- **Prerequisites**: Installed and reachable server.
- **Effects on server/inventory/resources**: Installs remote service and generates authentication credentials.
- **Related commands**: `redis:start`, `redis:restart`, `server:logs`.
- **Failure/guardrail behavior**: Stops on installation/configuration failures and reports credential handling issues clearly.

<a name="redis-start"></a>

## redis:start

- **What it does**: Starts Redis service.
- **When to use it**: Recovering runtime availability.
- **Prerequisites**: Redis installed and server reachable.
- **Effects on server/inventory/resources**: Starts remote data service.
- **Related commands**: `redis:stop`, `redis:restart`.
- **Failure/guardrail behavior**: Reports startup failures directly.

<a name="redis-stop"></a>

## redis:stop

- **What it does**: Stops Redis service.
- **When to use it**: Controlled maintenance operations.
- **Prerequisites**: Redis installed and server reachable.
- **Effects on server/inventory/resources**: Stops remote data service.
- **Related commands**: `redis:start`, `redis:restart`.
- **Failure/guardrail behavior**: Reports stop failures directly.

<a name="redis-restart"></a>

## redis:restart

- **What it does**: Restarts Redis service.
- **When to use it**: Service refresh after config/runtime changes.
- **Prerequisites**: Redis installed and server reachable.
- **Effects on server/inventory/resources**: Restarts remote data service.
- **Related commands**: `redis:start`, `redis:stop`, `server:logs`.
- **Failure/guardrail behavior**: Aborts on restart failures with diagnostic context.
