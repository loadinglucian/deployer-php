# Managing Services

<!-- toc -->

- [Nginx](#nginx)
    - [Controlling Nginx](#controlling-nginx)
    - [Viewing Logs](#viewing-logs)
- [PHP-FPM](#php-fpm)
    - [Controlling PHP-FPM](#controlling-php-fpm)
    - [Installing Additional PHP Versions](#installing-additional-php-versions)
    - [Viewing Logs](#viewing-logs-1)

<!-- /toc -->

DeployerPHP installs Nginx and PHP-FPM during `server:install`. These services share a consistent command pattern for control: start, stop, and restart. This guide covers how to manage these core web services on your servers.

## Nginx

Nginx serves as the web server for your applications, handling HTTP requests and proxying them to PHP-FPM. Site-specific Nginx configurations are managed automatically by `site:create` and `site:delete`, so you won't need to edit configuration files manually.

### Controlling Nginx

```shell
deployer nginx:start
deployer nginx:stop
deployer nginx:restart
```

Use restart after making manual configuration changes or when troubleshooting connection issues.

### Viewing Logs

To view Nginx service logs, use `server:logs` and select the nginx service. For site-specific access logs, select the site domain from the log sources.

## PHP-FPM

PHP-FPM (FastCGI Process Manager) processes PHP requests for your applications. During `server:install`, you select which PHP version to install. Each version runs its own PHP-FPM service.

### Controlling PHP-FPM

```shell
deployer php:start
deployer php:stop
deployer php:restart
```

When you have multiple PHP versions installed, you can target a specific version or omit the version to control all installed versions at once.

### Installing Additional PHP Versions

To install additional PHP versions on an existing server, run `server:install` again:

```shell
deployer server:install
```

This adds the new PHP version alongside existing versions without affecting running sites. Each site uses its own PHP version as configured during `site:create`.

### Viewing Logs

To view PHP-FPM logs, use `server:logs` and select the PHP-FPM service for your version.
