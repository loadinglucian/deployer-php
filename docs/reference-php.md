# Command Reference: PHP-FPM

<!-- toc -->

- [php:start](#php-start)
- [php:stop](#php-stop)
- [php:restart](#php-restart)

<!-- /toc -->

Use these commands to control PHP-FPM services for installed PHP versions.

<a name="php-start"></a>

## php:start

- **What it does**: Starts PHP-FPM service processes.
- **When to use it**: Bringing PHP execution online after maintenance or failure.
- **Prerequisites**: PHP-FPM installed on target server.
- **Effects on server/inventory/resources**: Starts remote runtime service.
- **Related commands**: `php:stop`, `php:restart`, `server:logs`.
- **Failure/guardrail behavior**: Returns service-level startup diagnostics.

<a name="php-stop"></a>

## php:stop

- **What it does**: Stops PHP-FPM service processes.
- **When to use it**: Planned maintenance or deep troubleshooting.
- **Prerequisites**: PHP-FPM installed on target server.
- **Effects on server/inventory/resources**: Stops remote runtime service.
- **Related commands**: `php:start`, `php:restart`.
- **Failure/guardrail behavior**: Fails with actionable errors when stop operations are blocked.

<a name="php-restart"></a>

## php:restart

- **What it does**: Restarts PHP-FPM services.
- **When to use it**: Applying runtime refreshes and post-deploy runtime resets.
- **Prerequisites**: PHP-FPM installed on target server.
- **Effects on server/inventory/resources**: Restarts remote runtime service.
- **Related commands**: `site:deploy`, `php:start`, `php:stop`.
- **Failure/guardrail behavior**: Aborts and reports restart failures per targeted service.
