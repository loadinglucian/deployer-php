# Documentation

DeployerPHP documentation is organized as a progressive guide.

Everything you need to deploy PHP applications:

<a name="guides"></a>

## Guides

- [Introduction](../README.md)
- [Installation](installation.md)
- [Zero to Deploy](zero-to-deploy.md)
- [Automation & AI Guide](automation.md)

<a name="references"></a>

## References

### Server & Site Operations

- [Server](reference-server.md)
- [Site](reference-site.md)

### Scheduling & Process Control

- [Cron](reference-cron.md)
- [Supervisor](reference-supervisor.md)

### Web Runtime Services

- [Nginx](reference-nginx.md)
- [PHP-FPM](reference-php.md)

### Data Services

- [MariaDB](reference-mariadb.md)
- [PostgreSQL](reference-postgresql.md)
- [Redis](reference-redis.md)
- [Memcached](reference-memcached.md)

### Cloud Providers

- [AWS](reference-aws.md)
- [Cloudflare](reference-cloudflare.md)
- [DigitalOcean](reference-digitalocean.md)

### Scaffolding

- [Scaffold](reference-scaffold.md)

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
