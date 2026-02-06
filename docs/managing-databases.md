# Managing Databases

<!-- toc -->

- [Relational Databases](#relational-databases)
    - [MariaDB](#mariadb)
    - [PostgreSQL](#postgresql)
- [Key-Value Stores](#key-value-stores)
    - [Redis](#redis)
    - [Memcached](#memcached)
- [Credentials](#credentials)
    - [What Gets Generated](#what-gets-generated)
    - [Receiving Credentials](#receiving-credentials)
    - [File Format](#file-format)

<!-- /toc -->

Most applications need persistent data storage. DeployerPHP supports installing and managing relational databases (MariaDB, PostgreSQL) and key-value stores (Redis, Memcached) on your servers.

> [!NOTE]
> DeployerPHP installs databases from your Ubuntu release's native packages and handles all configuration automatically.

## Relational Databases

### MariaDB

MariaDB is a community-developed, open-source relational database with enhanced features and active development. Install it with:

```shell
deployer mariadb:install
```

During installation, DeployerPHP installs the MariaDB server package, generates a secure root password, creates a `deployer` database user with its own password, and creates a `deployer` database ready for your application.

Control the service with:

```shell
deployer mariadb:start
deployer mariadb:stop
deployer mariadb:restart
```

To view MariaDB logs, use `server:logs` and select the mariadb service.

### PostgreSQL

PostgreSQL is a powerful, open-source object-relational database system known for its reliability and feature set. Install it with:

```shell
deployer postgresql:install
```

Like MariaDB, this creates credentials for the `deployer` user and a `deployer` database.

Control the service with:

```shell
deployer postgresql:start
deployer postgresql:stop
deployer postgresql:restart
```

To view PostgreSQL logs, use `server:logs` and select the postgres service.

## Key-Value Stores

### Redis

Redis is an in-memory data structure store, commonly used for caching, sessions, and queues. Install it with:

```shell
deployer redis:install
```

During installation, DeployerPHP installs the Redis server package, generates a secure password for authentication, and configures Redis to bind to localhost only for security.

Control the service with:

```shell
deployer redis:start
deployer redis:stop
deployer redis:restart
```

To view Redis logs, use `server:logs` and select the redis-server service.

### Memcached

Memcached is a distributed memory caching system, useful for caching database queries and session data. Install it with:

```shell
deployer memcached:install
```

Control the service with:

```shell
deployer memcached:start
deployer memcached:stop
deployer memcached:restart
```

To view Memcached logs, use `server:logs` and select the memcached service.

## Credentials

When installing databases and key-value stores that require authentication (MariaDB, PostgreSQL, Redis), DeployerPHP generates secure credentials during installation. These credentials are for accessing the database on the remote server, but they're saved to your local machine where you're running the `deployer` command.

### What Gets Generated

**Relational databases** (MariaDB, PostgreSQL) generate:

- Root/admin password for full database access
- Application user (`deployer`) with its own password
- Application database (`deployer`) ready for your app

**Key-value stores** (Redis) generate:

- A single authentication password

### Receiving Credentials

During installation, you'll be prompted to choose how to receive the credentials:

- **Display on screen** - Shows credentials in your terminal (default)
- **Save to file** - Downloads credentials to your local machine

If you choose to save, DeployerPHP prompts for a file path. The default suggestions are `.env.mariadb`, `.env.postgresql`, `.env.redis`, etc. The path is relative to your current working directory, so credentials are typically saved alongside your project files.

Credential files are created with `0600` permissions (owner read/write only) for security. If the file already exists, new credentials are appended with a separator, making it easy to store credentials for multiple servers in one file.

### File Format

Saved credential files use `.env` format with environment variables and ready-to-use connection strings:

```env
# MariaDB Credentials for production
MARIADB_ROOT_PASSWORD=...
MARIADB_DATABASE=deployer
MARIADB_USER=deployer
MARIADB_PASSWORD=...
DATABASE_URL=mysql://deployer:...@localhost/deployer
```

You can copy these values directly into your application's `.env` file.

> [!WARNING]
> Credentials are generated only once during installation. If you choose to display them, copy them immediately. DeployerPHP won't be able to show them again.

If saving to a file fails, DeployerPHP automatically falls back to displaying the credentials on screen so you don't lose them.
