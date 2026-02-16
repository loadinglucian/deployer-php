# Command Reference: MariaDB

<!-- toc -->

- [mariadb:install](#mariadb-install)
- [mariadb:start](#mariadb-start)
- [mariadb:stop](#mariadb-stop)
- [mariadb:restart](#mariadb-restart)

<!-- /toc -->

Use these commands to install and operate MariaDB on managed servers.

<a name="mariadb-install"></a>

## mariadb:install

- **What it does**: Installs MariaDB and initializes an application-ready database/user setup.
- **When to use it**: Adding MariaDB to a server for application persistence.
- **Prerequisites**: Installed and reachable server.
- **Effects on server/inventory/resources**: Installs remote database service and creates initial credentials.
- **Related commands**: `mariadb:start`, `mariadb:restart`, `server:logs`.
- **Failure/guardrail behavior**: Returns contextual installation errors and preserves credential safety behavior on output/storage failures.

<a name="mariadb-start"></a>

## mariadb:start

- **What it does**: Starts MariaDB service.
- **When to use it**: Recovering database service availability.
- **Prerequisites**: MariaDB installed and server reachable.
- **Effects on server/inventory/resources**: Starts remote database service.
- **Related commands**: `mariadb:stop`, `mariadb:restart`.
- **Failure/guardrail behavior**: Reports service startup failures.

<a name="mariadb-stop"></a>

## mariadb:stop

- **What it does**: Stops MariaDB service.
- **When to use it**: Planned maintenance operations.
- **Prerequisites**: MariaDB installed and server reachable.
- **Effects on server/inventory/resources**: Stops remote database service.
- **Related commands**: `mariadb:start`, `mariadb:restart`.
- **Failure/guardrail behavior**: Returns service stop errors directly.

<a name="mariadb-restart"></a>

## mariadb:restart

- **What it does**: Restarts MariaDB service.
- **When to use it**: Applying service refresh after config/runtime changes.
- **Prerequisites**: MariaDB installed and server reachable.
- **Effects on server/inventory/resources**: Restarts remote database service.
- **Related commands**: `mariadb:start`, `mariadb:stop`, `server:logs`.
- **Failure/guardrail behavior**: Stops on restart failure with service context.
