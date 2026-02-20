# Managing Databases

<!-- toc -->

- [Service Types](#service-types)
- [Credential Generation and Delivery](#credential-generation-and-delivery)
- [Saved Credential Files](#saved-credential-files)
- [Security Notes](#security-notes)
- [Related References](#related-references)

<!-- /toc -->

DeployerPHP supports relational and key-value data services, with a consistent install-and-operate workflow. This guide focuses on credential handling and operational safety.

<a name="service-types"></a>

## Service Types

Use these namespaces based on your workload:

- `mariadb:*` for MariaDB.
- `postgresql:*` for PostgreSQL.
- `redis:*` for Redis.
- `memcached:*` for Memcached.

MariaDB, PostgreSQL, and Redis include credential flows during installation. Memcached does not include credential output flow.

<a name="credential-generation-and-delivery"></a>

## Credential Generation and Delivery

When you install MariaDB, PostgreSQL, or Redis, DeployerPHP generates service credentials.

You can receive credentials by:

- Displaying them in your terminal.
- Saving them to a local file.

> [!IMPORTANT]
> Treat installation credentials as sensitive, one-time operational output. Store them in your secrets workflow immediately.

If credential file writing fails, DeployerPHP falls back to terminal display so credentials are still delivered.

<a name="saved-credential-files"></a>

## Saved Credential Files

When you choose file output, credential files are saved locally with restrictive permissions.

- File mode is `0600` (owner read/write only).
- Files use `.env`-style key/value content.
- Existing files are appended so you can keep multi-server credentials together.

Example format:

```dotenv
# MariaDB Credentials for production
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=deployer
DB_USERNAME=deployer
DB_PASSWORD=super-secret
```

<a name="security-notes"></a>

## Security Notes

- Do not commit generated credential files.
- Rotate credentials when access scope changes.
- Limit who can read local credential artifacts.

> [!IMPORTANT]
> If credentials are displayed in a shared terminal session, assume exposure and rotate immediately.

## Related References

- [MariaDB Reference](reference-mariadb.md)
- [PostgreSQL Reference](reference-postgresql.md)
- [Redis Reference](reference-redis.md)
- [Memcached Reference](reference-memcached.md)
