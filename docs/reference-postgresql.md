# Command Reference: PostgreSQL

<!-- toc -->

- [postgresql:install](#postgresql-install)
- [postgresql:start](#postgresql-start)
- [postgresql:stop](#postgresql-stop)
- [postgresql:restart](#postgresql-restart)

<!-- /toc -->

Use these commands to install and operate PostgreSQL on managed servers.

<a name="postgresql-install"></a>

## postgresql:install

- **What it does**: Installs PostgreSQL and initializes application-ready database credentials.
- **When to use it**: Adding PostgreSQL to a server for transactional or relational workloads.
- **Prerequisites**: Installed and reachable server.
- **Effects on server/inventory/resources**: Installs remote database service and creates initial credentials.
- **Related commands**: `postgresql:start`, `postgresql:restart`, `server:logs`.
- **Failure/guardrail behavior**: Returns contextual installation errors and credential handling safeguards.

<a name="postgresql-start"></a>

## postgresql:start

- **What it does**: Starts PostgreSQL service.
- **When to use it**: Recovering service availability.
- **Prerequisites**: PostgreSQL installed and server reachable.
- **Effects on server/inventory/resources**: Starts remote database service.
- **Related commands**: `postgresql:stop`, `postgresql:restart`.
- **Failure/guardrail behavior**: Reports startup failures directly.

<a name="postgresql-stop"></a>

## postgresql:stop

- **What it does**: Stops PostgreSQL service.
- **When to use it**: Planned maintenance windows.
- **Prerequisites**: PostgreSQL installed and server reachable.
- **Effects on server/inventory/resources**: Stops remote database service.
- **Related commands**: `postgresql:start`, `postgresql:restart`.
- **Failure/guardrail behavior**: Reports stop failures directly.

<a name="postgresql-restart"></a>

## postgresql:restart

- **What it does**: Restarts PostgreSQL service.
- **When to use it**: Reloading service runtime after operational changes.
- **Prerequisites**: PostgreSQL installed and server reachable.
- **Effects on server/inventory/resources**: Restarts remote database service.
- **Related commands**: `postgresql:start`, `postgresql:stop`, `server:logs`.
- **Failure/guardrail behavior**: Aborts on restart failures with service context.
