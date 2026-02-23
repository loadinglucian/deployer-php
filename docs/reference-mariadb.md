# Command Reference: MariaDB

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `mariadb:*` commands to install and operate MariaDB on managed servers.

## At a Glance

| Command           | Use it when you need to...                             |
| ----------------- | ------------------------------------------------------ |
| `mariadb:install` | install MariaDB and initialize application credentials |
| `mariadb:start`   | start the MariaDB service                              |
| `mariadb:stop`    | stop MariaDB for maintenance                           |
| `mariadb:restart` | restart MariaDB after operational changes              |

## Details

`mariadb:install` includes credential generation and delivery flow.

Lifecycle commands (`mariadb:start`, `mariadb:stop`, `mariadb:restart`) are for runtime control after installation.

## Safety and Guardrails

> [!INFO]
> Capture generated credentials during installation and store them in your secrets workflow.

> [!IMPORTANT]
> Stopping MariaDB impacts application reads and writes immediately.
