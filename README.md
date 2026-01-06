# DeployerPHP

```
▒ ▶ DeployerPHP ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
▒ The server and site deployment tool for PHP
```

[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

- [Introduction](#introduction)
- [Features](#features)
- [Contributing](#contributing)
- [License](#license)
- [Documentation](#documentation)

<a name="introduction"></a>

## Introduction

DeployerPHP allows you to easily manage, install, and deploy your servers and sites with configurations and build scripts stored directly in your repository, making everything documentable and PR-reviewable. You can install as many servers and deploy as many sites as you need.

As a native PHP/Composer package, it integrates seamlessly into your existing toolchain and workflows.

<a name="features"></a>

## Features

- **Unlimited servers and sites** - No limits, restrictions or vendor lock-in
- **Repository-driven configuration** - Configs and build scripts live with the rest of your code
- **End-to-end server management** - Provision cloud instances, install services, and manage operations
- **Composable commands** - Easily build automation pipelines to spin up servers, deploy sites, or run workflows on demand

**Pro Features:** DeployerPHP offers convenient integration features with third-party cloud providers like AWS, DigitalOcean and others. These features are free to use, although a modest subscription option may be introduced in the future to support development. The core server, site, and service management features will always remain free and unlimited.

## Contributing

Thank you for considering contributing to DeployerPHP! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## License

DeployerPHP is open-source software distributed under the [MIT License](./LICENSE).

You can use it freely for personal or commercial projects, without any restrictions.

## Documentation

- [Getting Started](docs/getting-started.md)
    - [Installation](docs/getting-started.md#installation)
    - [Requirements](docs/getting-started.md#requirements)
    - [How Commands Are Organized](docs/getting-started.md#how-commands-are-organized)
    - [Step 1: Adding a Server](docs/getting-started.md#add-new-server)
    - [Step 2: Installing a Server](docs/getting-started.md#install-new-server)
    - [Step 3: Creating a Site](docs/getting-started.md#create-new-site)
    - [Step 4: Deploying a Site](docs/getting-started.md#deploy-site)
    - [Step 5: Enabling HTTPS](docs/getting-started.md#enable-https)
    - [AI Rules for Debugging](docs/getting-started.md#scaffold-ai-rules)
- [Server Management](docs/servers.md)
    - [Adding a Server](docs/servers.md#adding-a-server)
    - [Installing a Server](docs/servers.md#installing-a-server)
    - [Server Information](docs/servers.md#server-information)
    - [Firewall Configuration](docs/servers.md#firewall-configuration)
    - [Running Commands](docs/servers.md#running-commands)
    - [SSH Access](docs/servers.md#ssh-access)
    - [Viewing Logs](docs/servers.md#viewing-logs)
    - [Deleting a Server](docs/servers.md#deleting-a-server)
- [Services](docs/services.md)
    - [MySQL](docs/services.md#mysql)
    - [MariaDB](docs/services.md#mariadb)
    - [PostgreSQL](docs/services.md#postgresql)
    - [Redis](docs/services.md#redis)
    - [Memcached](docs/services.md#memcached)
    - [Valkey](docs/services.md#valkey)
    - [Nginx](docs/services.md#nginx)
    - [PHP-FPM](docs/services.md#php-fpm)
- [Site Management](docs/sites.md)
    - [Creating a Site](docs/sites.md#creating-a-site)
    - [Deploying a Site](docs/sites.md#deploying-a-site)
    - [Enabling HTTPS](docs/sites.md#enabling-https)
    - [Shared Files](docs/sites.md#shared-files)
    - [Viewing Logs](docs/sites.md#viewing-logs)
    - [SSH Access](docs/sites.md#ssh-access)
    - [Rollbacks](docs/sites.md#rollbacks)
    - [Deleting a Site](docs/sites.md#deleting-a-site)
    - [Cron Jobs](docs/sites.md#cron-jobs)
    - [Supervisor Processes](docs/sites.md#supervisor-processes)
    - [Scaffolding](docs/sites.md#scaffolding)
- [Pro](docs/pro.md)
    - [AWS EC2](docs/pro.md#aws-ec2)
    - [DigitalOcean](docs/pro.md#digitalocean)
