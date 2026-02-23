# Logs & Debugging

<!-- toc -->

- [The Triage Pyramid](#the-triage-pyramid)
- [Server Info](#server-info)
- [Viewing Logs](#viewing-logs)
- [Shell Access](#shell-access)
- [Remote Commands](#remote-commands)
- [Next Steps](#next-steps)

<!-- /toc -->

As a seasoned developer, you already know that deployments are really straightforward and usually go off without a hitch. Nothing can really go wrong when orchestrating many different layers of complex software that need to interact seamlessly with each other.

However, in the very rare cases when something does occur, this guide will walk you through debugging using DeployerPHP.

<a name="the-triage-pyramid"></a>

## The Triage Pyramid

Start at the top and work your way down:

1. **`server:info`** - Get the big picture. Check hardware, resource usage, services, firewall, PHP-FPM stats, and site state.
2. **`server:logs`** - Narrow the search. Pull targeted logs from system, services, sites, crons, or supervisors to find errors.
3. **`server:ssh`** / **`site:ssh`** / **`server:run`** - Investigate directly. Drop into a shell for interactive exploration, or run a single diagnostic command.

<a name="server-info"></a>

## Server Info

Run the `server:info` command as your first diagnostic checkpoint:

```shell
deployer server:info
```

This gives you a single-screen overview of your server's health. Here's what to look for when debugging:

- **Hardware** - CPU cores, RAM, load averages, memory usage, disk type, and root disk capacity/usage/free space show whether you're hitting resource limits.
- **Server pressure** - The command highlights load and memory usage when pressure is high for the server's available resources, so hot spots stand out immediately.
- **Services and ports** - Every listening service is shown with its port number. If a service you expect isn't listed, it's either stopped or misconfigured.
- **PHP-FPM pool stats** - Active and idle process counts show current load. The "Max Children Reached" counter is your canary for capacity problems: if it's climbing, your FPM pool is too small for the traffic. Slow request counts highlight performance bottlenecks in your application code.
- **Nginx** - Active connections and total request counts give you a quick traffic snapshot.
- **Sites** - Each site's HTTP/HTTPS state and PHP version at a glance.

### Server Pressure

The command highlights `Load` and `Memory Used` in yellow when pressure is elevated and red when it's critical, so hot spots stand out without requiring you to interpret raw numbers.

**Load** appears as three averages: `0.25 / 0.30 / 0.35 (1m/core: 0.06)`. The three values are the 1-minute, 5-minute, and 15-minute load averages, followed by the 1-minute average divided by the number of CPU cores. That per-core ratio is what drives the highlighting: it normalizes load for server size, so the same thresholds apply whether your server has one core or sixteen. A ratio at or above `1.0` turns yellow; at or above `1.5` turns red.

When reading the trend, compare the 1m and 15m values. A 1m value rising above 15m means pressure is building. A 1m value falling below 15m means it's easing off.

**Memory Used** appears as `1.0 GB / 8.0 GB (12%)`. The percentage reflects currently available memory, not only free memory, so reclaimable page cache counts in your favor. This gives a realistic picture of actual pressure rather than a misleadingly high one. Memory at or above `85%` turns yellow; at or above `92%` turns red.

<a name="viewing-logs"></a>

## Viewing Logs

Run the `server:logs` command to pull relevant logs:

```shell
deployer server:logs
```

The command presents a multiselect menu of every log source available on your server. You can pick one or several sources in a single session, which is especially useful for cross-correlating events across services.

### Narrowing by Site

If you already know which site is affected, you can filter the log menu to that site's sources only. This strips away unrelated noise and shows the site's access log alongside its cron and supervisor logs.

### Group Shortcuts

When you need a broader view, the multiselect menu includes group shortcuts:

- **All site access logs** - Every site's Nginx access log in one pass
- **Cron service + all script logs** - The cron journal plus every site's cron script logs
- **Supervisor service + all program logs** - The supervisor journal plus every site's program logs

### Error Highlighting

Log output isn't a wall of undifferentiated text. Lines containing error keywords (`error`, `exception`, `fail`, `fatal`, `panic`) and HTTP 5xx status codes (500, 502, 503, 504) are visually highlighted, so problem lines stand out immediately when scanning through output.

<a name="shell-access"></a>

## Shell Access

When you need a full shell, DeployerPHP provides two commands. Both connect as the same SSH user with full server access:

```shell
deployer server:ssh
```

```shell
deployer site:ssh
```

The only difference is that `server:ssh` lands you in the configured SSH user's default login directory, while `site:ssh` drops you directly into the site's directory at `/home/deployer/sites/{domain}`, saving you the navigation step when you already know which site you're investigating.

> [!IMPORTANT]
> Both SSH commands require the PHP `pcntl` extension.

<a name="remote-commands"></a>

## Remote Commands

When you need a single remote check without opening an interactive shell, use the `server:run` command:

```shell
deployer server:run
```

This is useful for quick diagnostics such as disk pressure (`df -h`), memory state (`free -m`), service status (`systemctl status nginx`), or network checks (`ss -tulpn`), while keeping your investigation repeatable.

<a name="next-steps"></a>

## Next Steps

With these debugging tools under your belt, you should be able to triage most issues quickly. An even quicker way is to let an AI agent do it for you. For more information, see [AI Automation](ai-automation.md).
