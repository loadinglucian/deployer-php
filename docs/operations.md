# Operations

<!-- toc -->

- [Service Lifecycle](#service-lifecycle)
    - [The Pattern](#the-pattern)
    - [When to Restart](#when-to-restart)
- [Firewall Management](#firewall-management)
- [Server Deletion](#server-deletion)
- [Site Deletion](#site-deletion)

<!-- /toc -->

With your server installed, your application deployed, and your scheduled tasks and workers running, the remaining day-to-day work comes down to service control, firewall management, and cleanup.

<a name="service-lifecycle"></a>

## Service Lifecycle

<a name="the-pattern"></a>

### The Pattern

Every service namespace in DeployerPHP follows the same control convention:

| Namespace      | Service    |
| -------------- | ---------- |
| `nginx:*`      | Nginx      |
| `php:*`        | PHP-FPM    |
| `mariadb:*`    | MariaDB    |
| `postgresql:*` | PostgreSQL |
| `redis:*`      | Redis      |
| `memcached:*`  | Memcached  |
| `supervisor:*` | Supervisor |

Each namespace provides `*:start`, `*:stop`, and `*:restart` commands. The behavior is consistent across all of them: start brings a service online, stop takes it offline for maintenance, and restart cycles the process.

> [!NOTE]
> Stopping or restarting Supervisor affects every managed program on the server, not only the ones for a specific site. For more information, see [Crons & Supervisors](crons-and-supervisors.md#daemon-control)

<a name="when-to-restart"></a>

### When to Restart

Restart a service when:

- You've changed a configuration file on the server.
- OS-level package updates have touched a service.
- Logs or `server:info` show a service in a degraded or stuck state.

Don't restart as your first response to application errors. Check logs first, then apply the least disruptive action once you've identified the cause.

<a name="firewall-management"></a>

## Firewall Management

Run the `server:firewall` command to manage UFW rules:

```shell
deployer server:firewall
```

The command detects open ports and presents them as a multiselect prompt. The SSH port is automatically detected, is always allowed, and can't be deselected, so you won't accidentally lock yourself out.

<a name="server-deletion"></a>

## Server Deletion

Run the `server:delete` command to remove a server and its sites from inventory:

```shell
deployer server:delete
```

The command can also delete cloud-provisioned resources such as individual instances, volumes, assigned network interfaces, IPs, etc.

> [!IMPORTANT]
> Although reliable, cloud provider cleanup can sometimes be tricky. Make sure you double-check resource usage with your cloud provider to avoid incurring costs for orphaned resources.

<a name="site-deletion"></a>

## Site Deletion

Run the `site:delete` command to remove a site from inventory:

```shell
deployer site:delete
```

> [!IMPORTANT]
> Site deletion is destructive and cannot be undone. Validate backups and confirm scope before proceeding.
