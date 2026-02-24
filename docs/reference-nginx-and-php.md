# Nginx & PHP-FPM Reference

<!-- toc -->

- [At a Glance](#at-a-glance)
    - [Nginx Commands](#nginx-commands)
    - [PHP-FPM Commands](#php-fpm-commands)
- [Details](#details)
    - [Nginx Lifecycle](#nginx-lifecycle)
    - [PHP-FPM Lifecycle](#php-fpm-lifecycle)

<!-- /toc -->

Use `nginx:*` commands to control the web server runtime and `php:*` commands to control PHP-FPM services on managed hosts.

<a name="at-a-glance"></a>

## At a Glance

<a name="nginx-commands"></a>

### Nginx Commands

| Command         | Use it when you need to...                    |
| --------------- | --------------------------------------------- |
| `nginx:start`   | bring Nginx online                            |
| `nginx:stop`    | stop Nginx for controlled maintenance         |
| `nginx:restart` | reload Nginx state after changes or incidents |

<a name="php-fpm-commands"></a>

### PHP-FPM Commands

| Command       | Use it when you need to...                       |
| ------------- | ------------------------------------------------ |
| `php:start`   | start PHP-FPM services                           |
| `php:stop`    | stop PHP-FPM services for maintenance            |
| `php:restart` | restart PHP-FPM after deploys or runtime changes |

<a name="details"></a>

## Details

<a name="nginx-lifecycle"></a>

### Nginx Lifecycle

These commands are service lifecycle controls. Prefer `nginx:restart` for most recovery and post-change workflows.

If you are diagnosing traffic failures, inspect logs before and after service actions.

<a name="php-fpm-lifecycle"></a>

### PHP-FPM Lifecycle

By default, PHP-FPM commands affect all installed PHP-FPM versions on the server. You can target a specific version to avoid disrupting sites running on other PHP versions. When only one version is installed, it's targeted automatically.

`php:restart` is the common operational command after deployments to refresh runtime state. Use `php:start` and `php:stop` for explicit lifecycle control during maintenance windows.
