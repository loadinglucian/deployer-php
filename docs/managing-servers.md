# Managing Servers

<!-- toc -->

- [Checking Server Status](#checking-server-status)
- [Running Commands Remotely](#running-commands-remotely)
    - [Interactive SSH Sessions](#interactive-ssh-sessions)
- [Configuring the Firewall](#configuring-the-firewall)
- [Viewing Logs](#viewing-logs)
- [Removing a Server](#removing-a-server)
    - [Cloud-Provisioned Servers](#cloud-provisioned-servers)

<!-- /toc -->

Once you've added and installed a server, you'll need to monitor its status, run maintenance commands, and occasionally troubleshoot issues. DeployerPHP provides a set of `server:*` commands that let you manage your servers directly from your local machine without manually SSHing in for most tasks.

These commands work with any server in your inventory, whether it's a physical server, VPS, or cloud instance. If you haven't added a server yet, please read [Zero to Deploy](/docs/zero-to-deploy) to get started.

## Checking Server Status

Your first step when managing a server is usually checking what's running. The `server:info` command gives you a complete dashboard of your server's current state:

```shell
deployer server:info
```

This displays:

- **Distribution** - OS version and architecture
- **User permissions** - Whether you're connecting as root or a standard user
- **Hardware** - CPU cores, memory, and disk usage
- **Services** - Status of installed services (MariaDB, Redis, etc.)
- **Firewall** - UFW status and allowed ports
- **Nginx** - Whether Nginx is running and its configuration status
- **PHP versions** - All installed PHP versions and the default CLI version
- **PHP-FPM** - Pool status and active processes per version
- **Sites** - All sites configured on this server

This gives you a complete picture of your server without needing to SSH in and run multiple diagnostic commands.

## Running Commands Remotely

Sometimes you need to run a quick command on your server, like checking disk space or restarting a service. The `server:run` command lets you execute any shell command without opening a full terminal session:

```shell
deployer server:run
```

DeployerPHP will prompt you for:

- **Server** - Which server to run the command on
- **Command** - The shell command to execute

The command runs immediately and streams output back to your terminal in real-time. This is perfect for one-off tasks like `df -h`, `free -m`, or `systemctl restart nginx`.

### Interactive SSH Sessions

For longer work sessions where you need to run multiple commands or navigate the filesystem, use `server:ssh` to drop into an interactive terminal:

```shell
deployer server:ssh
```

This opens a full SSH session to your server. Use `exit` or press `Ctrl+D` to return to your local machine.

> [!INFO]
> Interactive SSH sessions require PHP's `pcntl` extension to be installed on your local machine. Most PHP installations include this by default.

## Configuring the Firewall

Controlling which ports are exposed to the internet is essential for server security. The `server:firewall` command helps you configure UFW (Uncomplicated Firewall) by detecting which services are listening and letting you choose which to allow:

```shell
deployer server:firewall
```

DeployerPHP will:

1. Scan for services listening on ports
2. Show you each service and its port
3. Let you select which ports to allow through the firewall
4. Apply the UFW rules

> [!INFO]
> SSH access (port 22) is always preserved regardless of your selections. DeployerPHP won't let you lock yourself out of your server.

## Viewing Logs

When something goes wrong, or you need to monitor activity, logs are your first stop. The `server:logs` command provides a unified view of logs from multiple sources:

```shell
deployer server:logs
```

DeployerPHP presents a multiselect prompt where you can choose from:

- **System logs** - syslog and authentication logs
- **Service logs** - Nginx, MariaDB, PostgreSQL, Redis, and other installed services
- **Site access logs** - Nginx access logs for your sites
- **Cron logs** - Output from scheduled cron jobs
- **Supervisor logs** - Output from supervisor-managed processes

You can select multiple sources to view them together, making it easy to correlate events across different parts of your stack.

## Removing a Server

When you no longer need a server, you can remove it from your inventory with `server:delete`:

```shell
deployer server:delete
```

This command includes safety features to prevent accidental deletion:

1. **Type-to-confirm** - You must type the server name to proceed
2. **Double confirmation** - An additional prompt asks you to confirm the deletion

When you delete a server, any sites associated with that server are also removed from your inventory.

### Cloud-Provisioned Servers

If the server was provisioned through a cloud provider using DeployerPHP, you'll be prompted whether to also destroy the cloud instance. This gives you explicit control over cloud resources:

- **Yes** - Destroys the cloud instance and releases associated resources
- **No** - Only removes the server from your local inventory, leaving the cloud instance running

> [!IMPORTANT]
> If you choose not to destroy the cloud instance, your server will continue running and incurring costs. Check with your cloud provider to ensure it is fully terminated when no longer needed.

If cloud destruction fails (for example, due to API errors), you'll be prompted whether to remove the server from inventory anyway.
