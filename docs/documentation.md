# Documentation

<!-- toc -->

- [Guides](#guides)
- [References](#references)
- [Cloud Providers](#cloud-providers)
- [Images](#images)

<!-- /toc -->

DeployerPHP documentation is split into guides and references. Use guides for workflows and operational decisions, and use references for fast command lookup.

<a name="guides"></a>

## Guides

- [Introduction](../README.md)
- [Installation](installation.md)
- [Zero to Deploy](zero-to-deploy.md)
- [Crons & Supervisors](crons-and-supervisors.md)
- [Logs & Debugging](logs-and-debugging.md)
- [AI Automation](ai-automation.md)
- [Managing Servers](managing-servers.md)
- [Managing Sites](managing-sites.md)
- [Managing Services](managing-services.md)
- [Managing Databases](managing-databases.md)
- [Cloud Providers](cloud-providers.md)

<a name="references"></a>

## References

### Server & Site Operations

- [Server Reference](reference-server.md)
- [Site Reference](reference-site.md)

### Scheduling & Process Control

- [Cron Reference](reference-cron.md)
- [Supervisor Reference](reference-supervisor.md)

### Web Runtime Services

- [Nginx Reference](reference-nginx.md)
- [PHP-FPM Reference](reference-php.md)

### Data Services

- [MariaDB Reference](reference-mariadb.md)
- [PostgreSQL Reference](reference-postgresql.md)
- [Redis Reference](reference-redis.md)
- [Memcached Reference](reference-memcached.md)

### Scaffolding

- [Scaffold Reference](reference-scaffold.md)

<a name="cloud-providers"></a>

## Cloud Providers

- [AWS Reference](reference-aws.md)
- [Cloudflare Reference](reference-cloudflare.md)
- [DigitalOcean Reference](reference-digitalocean.md)

## Images

When embedding docs images, use `docs/images/` paths. If a dark variant exists
with the `-dark` suffix (for example `deployerphp-dark.webp`), render both
images and toggle using `dark:hidden` / `hidden dark:block` classes.

```html
<p>
    <img src="./light.webp" alt="" class="dark:hidden" />
    <img src="./dark.webp" alt="" class="hidden dark:block" />
</p>
```
