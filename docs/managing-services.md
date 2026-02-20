# Managing Services

<!-- toc -->

- [Service Lifecycle Pattern](#service-lifecycle-pattern)
- [Web Runtime Services](#web-runtime-services)
- [Data Services](#data-services)
- [Troubleshooting Workflow](#troubleshooting-workflow)
- [Related References](#related-references)

<!-- /toc -->

Most operational incidents come down to service state, not deployment syntax. This guide helps you choose the right service action, validate impact, and recover quickly.

<a name="service-lifecycle-pattern"></a>

## Service Lifecycle Pattern

Across DeployerPHP namespaces, service control follows the same pattern:

- `*:start` brings a service online.
- `*:stop` is for maintenance windows and controlled interventions.
- `*:restart` is the usual recovery path after config or runtime updates.

Use restarts intentionally. If you only need visibility, start with `server:info` and `server:logs` before touching service state.

<a name="web-runtime-services"></a>

## Web Runtime Services

Nginx and PHP-FPM are your core request path services.

- Use `nginx:*` for web server control.
- Use `php:*` for PHP runtime process control.

When requests fail, check these first before investigating application code.

<a name="data-services"></a>

## Data Services

Data services follow the same lifecycle model, with install commands where applicable:

- Relational: `mariadb:*`, `postgresql:*`
- Key-value/cache: `redis:*`, `memcached:*`

Keep service operations small and reversible. Apply one change, validate, then continue.

<a name="troubleshooting-workflow"></a>

## Troubleshooting Workflow

A reliable sequence is:

1. Inspect current state (`server:info`).
2. Collect evidence (`server:logs`).
3. Apply the least disruptive service action.
4. Re-check logs and runtime state.

> [!INFO]
> Restart loops without diagnosis hide the real issue. Capture log context before and after any restart.

## Related References

- [Nginx Reference](reference-nginx.md)
- [PHP-FPM Reference](reference-php.md)
- [MariaDB Reference](reference-mariadb.md)
- [PostgreSQL Reference](reference-postgresql.md)
- [Redis Reference](reference-redis.md)
- [Memcached Reference](reference-memcached.md)
