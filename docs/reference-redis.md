# Command Reference: Redis

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)

<!-- /toc -->

Use `redis:*` commands to install and operate Redis on managed servers.

<a name="at-a-glance"></a>

## At a Glance

| Command         | Use it when you need to...                 |
| --------------- | ------------------------------------------ |
| `redis:install` | install Redis and configure authentication |
| `redis:start`   | start Redis                                |
| `redis:stop`    | stop Redis for maintenance                 |
| `redis:restart` | restart Redis after operational changes    |

<a name="details"></a>

## Details

`redis:install` configures secure local access and credential handling.

Lifecycle commands (`redis:start`, `redis:stop`, `redis:restart`) control runtime state after installation.

<a name="safety-and-guardrails"></a>

## Safety and Guardrails

> [!NOTE]
> Record Redis credentials at install time and rotate if exposure is suspected.

> [!IMPORTANT]
> Stopping Redis can disrupt caches, queues, and application workflows that depend on it.
