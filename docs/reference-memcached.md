# Command Reference: Memcached

<!-- toc -->

- [At a Glance](#at-a-glance)
- [Details](#details)
- [Safety and Guardrails](#safety-and-guardrails)
- [Related Guides](#related-guides)

<!-- /toc -->

Use `memcached:*` commands to install and operate Memcached on managed servers.

## At a Glance

| Command             | Use it when you need to...                  |
| ------------------- | ------------------------------------------- |
| `memcached:install` | install Memcached                           |
| `memcached:start`   | start Memcached                             |
| `memcached:stop`    | stop Memcached for maintenance              |
| `memcached:restart` | restart Memcached after operational changes |

## Details

`memcached:install` sets up the service without a credential output flow.

Lifecycle commands (`memcached:start`, `memcached:stop`, `memcached:restart`) control runtime state after installation.

## Safety and Guardrails

> [!IMPORTANT]
> Stopping Memcached can degrade application performance and can surface stale-cache assumptions.

## Related Guides

- [Managing Databases](managing-databases.md)
- [Managing Services](managing-services.md)
