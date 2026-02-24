# Databases Reference

<!-- toc -->

- [At a Glance](#at-a-glance)
    - [MariaDB Commands](#mariadb-commands)
    - [PostgreSQL Commands](#postgresql-commands)
    - [Redis Commands](#redis-commands)
    - [Memcached Commands](#memcached-commands)
- [Details](#details)
    - [Installation and Credentials](#installation-and-credentials)
    - [Memcached Installation](#memcached-installation)
    - [Lifecycle Commands](#lifecycle-commands)

<!-- /toc -->

Use database commands to install and operate MariaDB, PostgreSQL, Redis, and Memcached on managed servers.

<a name="at-a-glance"></a>

## At a Glance

<a name="mariadb-commands"></a>

### MariaDB Commands

| Command           | Use it when you need to...                             |
| ----------------- | ------------------------------------------------------ |
| `mariadb:install` | install MariaDB and initialize application credentials |
| `mariadb:start`   | start the MariaDB service                              |
| `mariadb:stop`    | stop MariaDB for maintenance                           |
| `mariadb:restart` | restart MariaDB after operational changes              |

<a name="postgresql-commands"></a>

### PostgreSQL Commands

| Command              | Use it when you need to...                                |
| -------------------- | --------------------------------------------------------- |
| `postgresql:install` | install PostgreSQL and initialize application credentials |
| `postgresql:start`   | start PostgreSQL                                          |
| `postgresql:stop`    | stop PostgreSQL for maintenance                           |
| `postgresql:restart` | restart PostgreSQL after operational changes              |

<a name="redis-commands"></a>

### Redis Commands

| Command         | Use it when you need to...                 |
| --------------- | ------------------------------------------ |
| `redis:install` | install Redis and configure authentication |
| `redis:start`   | start Redis                                |
| `redis:stop`    | stop Redis for maintenance                 |
| `redis:restart` | restart Redis after operational changes    |

<a name="memcached-commands"></a>

### Memcached Commands

| Command             | Use it when you need to...                  |
| ------------------- | ------------------------------------------- |
| `memcached:install` | install Memcached                           |
| `memcached:start`   | start Memcached                             |
| `memcached:stop`    | stop Memcached for maintenance              |
| `memcached:restart` | restart Memcached after operational changes |

<a name="details"></a>

## Details

<a name="installation-and-credentials"></a>

### Installation and Credentials

MariaDB, PostgreSQL, and Redis share a credential delivery workflow during installation. After installing the service, you can choose to display credentials on screen or save them to a file (written with 0600 permissions, appending to existing credential files).

Each install command is idempotent: if the service is already installed, DeployerPHP detects it and skips reinstallation. Credentials are only generated on fresh installs, so capture them during the initial installation.

<a name="memcached-installation"></a>

### Memcached Installation

Memcached is configured for localhost-only access and does not require authentication. Because of this, `memcached:install` has no credential output flow. There are no passwords to capture or store.

<a name="lifecycle-commands"></a>

### Lifecycle Commands

All four services share the same `start`, `stop`, and `restart` pattern for runtime control after installation. These are straightforward service lifecycle commands.
