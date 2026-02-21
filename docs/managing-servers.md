# Managing Servers

<!-- toc -->

- [Checking Server Status](#checking-server-status)
- [Running Commands on Servers](#running-commands-on-servers)
- [Configuring the Firewall](#configuring-the-firewall)
- [Viewing Logs](#viewing-logs)
- [Removing a Server](#removing-a-server)
- [Related References](#related-references)

<!-- /toc -->

Once you have a server in inventory, most day-to-day operations come down to inspection, controlled remote actions, and safe cleanup. This guide explains the operational workflow around `server:*` commands so you can troubleshoot quickly without losing safety guardrails.

<a name="checking-server-status"></a>

## Checking Server Status

Start with `server:info` when you need to understand what is actually running.

```shell
deployer server:info
```

Use this output as your first triage checkpoint:

- Confirm OS/runtime state before changing anything.
- Check load averages and memory-used pressure before diagnosing slow commands or timeouts.
- Check root disk capacity, absolute usage, and free space before investigating write/deploy failures.
- Verify installed service versions before deploys.
- Check site-level context when debugging one application.
- Review firewall state and allowed ports before network changes.

`server:info` is designed as a single dashboard, so you do not need to SSH in and run multiple commands to get the first diagnostic picture.

> [!INFO]
> If a server command fails unexpectedly, run the `server:info` and `server:logs` commands first. You usually find the root cause faster than retrying mutating commands.

<a name="running-commands-on-servers"></a>

## Running Commands on Servers

Use `server:run` for one-off diagnostics and scripted checks:

```shell
deployer server:run
```

Use `server:ssh` when you need an interactive session for multi-step investigation:

```shell
deployer server:ssh
```

> [!IMPORTANT]
> Interactive SSH sessions require PHP's `pcntl` extension on your local machine.

Use `server:run` for fast commands such as resource checks or single service actions. Use `server:ssh` when you need to move around the filesystem, run multiple steps, or investigate state interactively.

<a name="configuring-the-firewall"></a>

## Configuring the Firewall

Use `server:firewall` to manage UFW while preserving safe access:

```shell
deployer server:firewall
```

A good workflow is:

1. Confirm current service ports with `server:info`.
2. Apply firewall updates during a controlled maintenance window.
3. Validate SSH connectivity immediately after changes.

> [!IMPORTANT]
> Always keep SSH access open before applying stricter firewall rules. Locking yourself out is easy to do and expensive to recover.

DeployerPHP keeps SSH safety in focus when applying firewall changes, but you should still validate access immediately after any network policy update.

<a name="viewing-logs"></a>

## Viewing Logs

Use `server:logs` to stream system, service, site, cron, and supervisor logs from one place:

```shell
deployer server:logs
```

During incident response, pair logs with `server:info` so you can connect failures to the server's current runtime state.

You can select multiple sources in one session, which helps correlate incidents across system logs, service logs, and site-level activity. For a complete walkthrough of the triage workflow, see [Logs & Debugging](logs-and-debugging.md).

<a name="removing-a-server"></a>

## Removing a Server

Use `server:delete` when you need to decommission a server or clean stale inventory:

```shell
deployer server:delete
```

For cloud-provisioned servers, deletion may also remove provider resources.

> [!IMPORTANT]
> Deleting cloud-backed servers can incur irreversible data loss and may still leave billable resources if provider-side cleanup only partially succeeds.

When cloud deletion fails, resolve provider-side state explicitly instead of assuming cleanup completed.

## Related References

- [Server Reference](reference-server.md)
- [Nginx Reference](reference-nginx.md)
- [PHP-FPM Reference](reference-php.md)
- [Cloud Providers](cloud-providers.md)
