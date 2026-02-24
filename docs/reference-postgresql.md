# Command Reference: PostgreSQL

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `postgresql:*` commands to install and operate PostgreSQL on managed servers.

<a name="at-a-glance"></a>

## At a Glance

| Command              | Use it when you need to...                                |
| -------------------- | --------------------------------------------------------- |
| `postgresql:install` | install PostgreSQL and initialize application credentials |
| `postgresql:start`   | start PostgreSQL                                          |
| `postgresql:stop`    | stop PostgreSQL for maintenance                           |
| `postgresql:restart` | restart PostgreSQL after operational changes              |

<a name="details"></a>

## Details

`postgresql:install` includes credential generation and delivery flow.

Lifecycle commands (`postgresql:start`, `postgresql:stop`, `postgresql:restart`) handle runtime control.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!NOTE]
> Treat generated installation credentials as sensitive and store them promptly.

> [!IMPORTANT]
> Stopping PostgreSQL interrupts dependent application traffic.
