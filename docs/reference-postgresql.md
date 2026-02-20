# Command Reference: PostgreSQL

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related Guides](#related-guides)

<!-- /toc -->

Use `postgresql:*` commands to install and operate PostgreSQL on managed servers.

## At a Glance

| Command              | Use it when you need to...                                |
| -------------------- | --------------------------------------------------------- |
| `postgresql:install` | install PostgreSQL and initialize application credentials |
| `postgresql:start`   | start PostgreSQL                                          |
| `postgresql:stop`    | stop PostgreSQL for maintenance                           |
| `postgresql:restart` | restart PostgreSQL after operational changes              |

## Details

`postgresql:install` includes credential generation and delivery flow.

Lifecycle commands (`postgresql:start`, `postgresql:stop`, `postgresql:restart`) handle runtime control.

## Safety and Guardrails

> [!INFO]
> Treat generated installation credentials as sensitive and store them promptly.

> [!IMPORTANT]
> Stopping PostgreSQL interrupts dependent application traffic.

## Related Guides

- [Managing Databases](managing-databases.md)
- [Managing Services](managing-services.md)
