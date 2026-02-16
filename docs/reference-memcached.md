# Command Reference: Memcached

<!-- toc -->

- [memcached:install](#memcached-install)
- [memcached:start](#memcached-start)
- [memcached:stop](#memcached-stop)
- [memcached:restart](#memcached-restart)

<!-- /toc -->

Use these commands to install and operate Memcached on managed servers.

<a name="memcached-install"></a>

## memcached:install

- **What it does**: Installs Memcached service for in-memory caching workloads.
- **When to use it**: Adding cache capacity for applications that rely on Memcached.
- **Prerequisites**: Installed and reachable server.
- **Effects on server/inventory/resources**: Installs remote cache service.
- **Related commands**: `memcached:start`, `memcached:restart`, `server:logs`.
- **Failure/guardrail behavior**: Surfaces installation failures with service context.

<a name="memcached-start"></a>

## memcached:start

- **What it does**: Starts Memcached service.
- **When to use it**: Recovering cache service availability.
- **Prerequisites**: Memcached installed and server reachable.
- **Effects on server/inventory/resources**: Starts remote cache service.
- **Related commands**: `memcached:stop`, `memcached:restart`.
- **Failure/guardrail behavior**: Reports startup failures directly.

<a name="memcached-stop"></a>

## memcached:stop

- **What it does**: Stops Memcached service.
- **When to use it**: Planned maintenance and troubleshooting.
- **Prerequisites**: Memcached installed and server reachable.
- **Effects on server/inventory/resources**: Stops remote cache service.
- **Related commands**: `memcached:start`, `memcached:restart`.
- **Failure/guardrail behavior**: Reports stop failures directly.

<a name="memcached-restart"></a>

## memcached:restart

- **What it does**: Restarts Memcached service.
- **When to use it**: Runtime refreshes after operational changes.
- **Prerequisites**: Memcached installed and server reachable.
- **Effects on server/inventory/resources**: Restarts remote cache service.
- **Related commands**: `memcached:start`, `memcached:stop`, `server:logs`.
- **Failure/guardrail behavior**: Aborts with restart diagnostics on failure.
