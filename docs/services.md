# Services

- [Introduction](#introduction)
- [MySQL](#mysql)
- [MariaDB](#mariadb)
- [PostgreSQL](#postgresql)
- [Redis](#redis)
- [Memcached](#memcached)
- [Valkey](#valkey)
- [Nginx](#nginx)
- [PHP-FPM](#php-fpm)

<a name="introduction"></a>

## Introduction

DeployerPHP can install and manage various services on your servers. Each service follows a consistent command pattern:

- `<service>:install` - Install the service
- `<service>:start` - Start the service
- `<service>:stop` - Stop the service
- `<service>:restart` - Restart the service

All commands accept a `--server` option to specify the target server, or will prompt you to select one interactively.

To view logs for any service, use the unified `server:logs` command. See the [Viewing Logs](/docs/servers#viewing-logs) section for details.

<a name="mysql"></a>

## MySQL

MySQL is a popular open-source relational database.

### Installing MySQL

```bash
deployer mysql:install --server=production
```

During installation, DeployerPHP will prompt you for:

- **Server** - The target server (or use `--server` option)
- **Credential output** - How to receive the generated credentials

The installation process:

1. Installs the MySQL server package
2. Generates a secure root password
3. Creates a `deployer` database user with its own password
4. Creates a `deployer` database

| Option                  | Description                                 |
| ----------------------- | ------------------------------------------- |
| `--server`              | Server name                                 |
| `--display-credentials` | Display credentials on screen               |
| `--save-credentials`    | Save credentials to file (0600 permissions) |

> [!WARNING]
> Credentials are generated only once during installation. If MySQL is already installed, credentials will not be displayed again.

If saving to a file fails, DeployerPHP will automatically fall back to displaying the credentials on screen so you don't lose them.

### Managing MySQL

```bash
# Start the service
deployer mysql:start --server=production

# Stop the service
deployer mysql:stop --server=production

# Restart the service
deployer mysql:restart --server=production
```

To view MySQL logs, use `server:logs --server=production --service=mysqld`.

<a name="mariadb"></a>

## MariaDB

MariaDB is a community-developed fork of MySQL with enhanced features.

### Installing MariaDB

```bash
deployer mariadb:install --server=production
```

During installation, DeployerPHP will prompt you for:

- **Server** - The target server (or use `--server` option)
- **Credential output** - How to receive the generated credentials

The installation process:

1. Installs the MariaDB server package
2. Generates a secure root password
3. Creates a `deployer` database user with its own password
4. Creates a `deployer` database

| Option                  | Description                                 |
| ----------------------- | ------------------------------------------- |
| `--server`              | Server name                                 |
| `--display-credentials` | Display credentials on screen               |
| `--save-credentials`    | Save credentials to file (0600 permissions) |

> [!WARNING]
> Credentials are displayed only once after installation. Choose to display them on screen or save to a file with secure permissions (0600).

If saving to a file fails, DeployerPHP will automatically fall back to displaying the credentials on screen so you don't lose them.

### Managing MariaDB

```bash
deployer mariadb:start --server=production
deployer mariadb:stop --server=production
deployer mariadb:restart --server=production
```

To view MariaDB logs, use `server:logs --server=production --service=mariadb`.

> [!NOTE]
> MySQL and MariaDB are mutually exclusive. Install only one on each server.

<a name="postgresql"></a>

## PostgreSQL

PostgreSQL is a powerful, open-source object-relational database system.

### Installing PostgreSQL

```bash
deployer postgresql:install --server=production
```

Like MySQL/MariaDB, this creates credentials for the `deployer` user and a `deployer` database.

### Managing PostgreSQL

```bash
deployer postgresql:start --server=production
deployer postgresql:stop --server=production
deployer postgresql:restart --server=production
```

To view PostgreSQL logs, use `server:logs --server=production --service=postgres`.

<a name="redis"></a>

## Redis

Redis is an in-memory data structure store, commonly used for caching and queues.

### Installing Redis

```bash
deployer redis:install --server=production
```

During installation, DeployerPHP:

1. Installs the Redis server package
2. Generates a secure password for authentication
3. Configures Redis to bind to localhost only

| Option                  | Description                                 |
| ----------------------- | ------------------------------------------- |
| `--server`              | Server name                                 |
| `--display-credentials` | Display credentials on screen               |
| `--save-credentials`    | Save credentials to file (0600 permissions) |

> [!WARNING]
> Credentials are displayed only once after installation. Choose to display them on screen or save to a file with secure permissions (0600).

### Managing Redis

```bash
deployer redis:start --server=production
deployer redis:stop --server=production
deployer redis:restart --server=production
```

To view Redis logs, use `server:logs --server=production --service=redis-server`.

<a name="memcached"></a>

## Memcached

Memcached is a distributed memory caching system.

### Installing Memcached

```bash
deployer memcached:install --server=production
```

### Managing Memcached

```bash
deployer memcached:start --server=production
deployer memcached:stop --server=production
deployer memcached:restart --server=production
```

To view Memcached logs, use `server:logs --server=production --service=memcached`.

<a name="valkey"></a>

## Valkey

Valkey is an open-source fork of Redis, fully compatible with Redis clients and commands.

### Installing Valkey

```bash
deployer valkey:install --server=production
```

During installation, DeployerPHP:

1. Installs the Valkey server package
2. Generates a secure password for authentication
3. Configures Valkey to bind to localhost only

| Option                  | Description                                 |
| ----------------------- | ------------------------------------------- |
| `--server`              | Server name                                 |
| `--display-credentials` | Display credentials on screen               |
| `--save-credentials`    | Save credentials to file (0600 permissions) |

> [!WARNING]
> Credentials are displayed only once after installation. Choose to display them on screen or save to a file with secure permissions (0600).

### Managing Valkey

```bash
deployer valkey:start --server=production
deployer valkey:stop --server=production
deployer valkey:restart --server=production
```

To view Valkey logs, use `server:logs --server=production --service=valkey-server`.

> [!NOTE]
> Valkey and Redis are mutually exclusive. Install only one on each server.

<a name="nginx"></a>

## Nginx

Nginx is installed automatically during `server:install`. These commands control the running service.

### Managing Nginx

```bash
# Start Nginx
deployer nginx:start --server=production

# Stop Nginx
deployer nginx:stop --server=production

# Restart Nginx (use after configuration changes)
deployer nginx:restart --server=production
```

To view Nginx service logs, use `server:logs --server=production --service=nginx`. For site-specific access logs, select the site domain from the log sources or use `--service=example.com`.

> [!NOTE]
> Site-specific Nginx configurations are managed automatically by `site:create` and `site:delete`.

<a name="php-fpm"></a>

## PHP-FPM

PHP-FPM is installed during `server:install` for each PHP version you select. These commands control PHP-FPM for all installed versions.

### Managing PHP-FPM

```bash
# Start PHP-FPM (all versions)
deployer php:start --server=production

# Stop PHP-FPM (all versions)
deployer php:stop --server=production

# Restart PHP-FPM (use after php.ini changes)
deployer php:restart --server=production
```

You can target a specific PHP version with any of the service commands:

```bash
deployer php:start --server=production --version=8.3
deployer php:stop --server=production --version=8.3
deployer php:restart --server=production --version=8.3
```

To view PHP-FPM logs, use `server:logs --server=production --service=php8.3-fpm` (replace `8.3` with your installed version).

### Installing Additional PHP Versions

To install additional PHP versions on an existing server, run `server:install` again:

```bash
deployer server:install \
    --server=production \
    --php-version=8.4 \
    --php-extensions=redis,imagick
```

This adds the new PHP version alongside existing versions without affecting running sites.
