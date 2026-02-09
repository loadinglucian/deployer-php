---
name: deployerphp-admin
description: Full-access DeployerPHP operator for provisioning, deployment, DNS, services, cron, supervisor, and debugging. Use when an agent must execute infrastructure changes through explicit non-interactive CLI commands with complete option values and safety confirmations.
---

# DeployerPHP (Admin Tier)

Use this skill for full lifecycle operations.

## Execution Protocol

1. Run commands in non-interactive form with explicit options.
2. Read `.deployer/inventory.yml` before changes.
3. Use `deployer help <command>` before first use in a session.
4. Include confirmation flags (`--yes`, `--force`) for destructive commands.
5. Do not use interactive SSH commands (`server:ssh`, `site:ssh`) from AI agents.

## Required Context

Set concrete values before running commands:

```bash
PROJECT_ROOT="/path/to/project"
ENV_FILE="$PROJECT_ROOT/.env"
INVENTORY_FILE="$PROJECT_ROOT/.deployer/inventory.yml"

SERVER="production"
HOST="203.0.113.50"
SSH_USER="root"
SSH_PORT="22"
SSH_PRIVATE_KEY="~/.ssh/id_rsa"
SSH_PUBLIC_KEY="~/.ssh/id_rsa.pub"

DOMAIN="example.com"
REPO="git@github.com:acme/app.git"
BRANCH="main"
PHP_VERSION="8.3"
WWW_MODE="redirect-to-root"
WEB_ROOT="public"

CRON_SCRIPT=".deployer/scripts/cron.sh"
CRON_SCHEDULE="*/5 * * * *"
SUPERVISOR_PROGRAM="queue-worker"
SUPERVISOR_SCRIPT=".deployer/scripts/supervisor.sh"

AWS_KEY_PAIR="deployer"
AWS_ZONE="example.com"
AWS_INSTANCE_TYPE="t3.micro"
AWS_IMAGE="ubuntu-24.04"
AWS_VPC="vpc-xxxxxxxx"
AWS_SUBNET="subnet-xxxxxxxx"

DO_REGION="nyc3"
DO_IMAGE="ubuntu-24-04-x64"
DO_SIZE="s-1vcpu-1gb"
DO_KEY_NAME="deployer"
DO_SSH_KEY_ID="123456"
DO_VPC_UUID="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
DO_ZONE="example.com"

CF_ZONE="example.com"
```

`CRON_SCRIPT` and `SUPERVISOR_SCRIPT` must be paths relative to project root.

## Global References

- Inventory: `.deployer/inventory.yml`
- Command catalog: `deployer list --raw`
- Per-command reference: `deployer help <command>`

## Scaffolding Commands

| Command            | Reference                        | Complete non-interactive example                                                                                                              |
| ------------------ | -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `scaffold:scripts` | `deployer help scaffold:scripts` | `deployer scaffold:scripts --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --destination="$PROJECT_ROOT" --force`                             |
| `scaffold:ai`      | `deployer help scaffold:ai`      | `deployer scaffold:ai --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --destination="$PROJECT_ROOT" --agent=".agents" --tier="admin" --force` |

## Server Commands

| Command           | Reference                       | Complete non-interactive example                                                                                                                                                                                                                                          |
| ----------------- | ------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `server:add`      | `deployer help server:add`      | `deployer server:add --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --name="$SERVER" --host="$HOST" --port="$SSH_PORT" --username="$SSH_USER" --private-key-path="$SSH_PRIVATE_KEY"`                                                                                     |
| `server:info`     | `deployer help server:info`     | `deployer server:info --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                                                                                                                                                                                 |
| `server:install`  | `deployer help server:install`  | `deployer server:install --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --generate-deploy-key --php-version="$PHP_VERSION" --php-default --php-extensions="bcmath,ctype,curl,dom,fileinfo,mbstring,openssl,pcntl,pdo,tokenizer,xml" --timezone="UTC"` |
| `server:firewall` | `deployer help server:firewall` | `deployer server:firewall --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --allow="22,80,443" --yes`                                                                                                                                                   |
| `server:logs`     | `deployer help server:logs`     | `deployer server:logs --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --service="system,nginx,php8.3-fpm" --lines=200`                                                                                                                                 |
| `server:run`      | `deployer help server:run`      | `deployer server:run --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --command="systemctl status nginx --no-pager"`                                                                                                                                    |
| `server:delete`   | `deployer help server:delete`   | `deployer server:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --force --yes --no-destroy-cloud`                                                                                                                                              |
| `server:ssh`      | `deployer help server:ssh`      | `Not supported for AI agents (interactive terminal). Use server:run.`                                                                                                                                                                                                     |

## Site Commands

| Command            | Reference                        | Complete non-interactive example                                                                                                                                                        |
| ------------------ | -------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `site:create`      | `deployer help site:create`      | `deployer site:create --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --server="$SERVER" --php-version="$PHP_VERSION" --www-mode="$WWW_MODE" --web-root="$WEB_ROOT"` |
| `site:deploy`      | `deployer help site:deploy`      | `deployer site:deploy --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --repo="$REPO" --branch="$BRANCH" --keep-releases=5 --yes --force`                             |
| `site:https`       | `deployer help site:https`       | `deployer site:https --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN"`                                                                                                |
| `site:dns:check`   | `deployer help site:dns:check`   | `deployer site:dns:check --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN"`                                                                                            |
| `site:shared:list` | `deployer help site:shared:list` | `deployer site:shared:list --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN"`                                                                                          |
| `site:shared:push` | `deployer help site:shared:push` | `deployer site:shared:push --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --local="$PROJECT_ROOT/.env.production" --remote=".env" --force --yes`                    |
| `site:shared:pull` | `deployer help site:shared:pull` | `deployer site:shared:pull --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --remote=".env" --local="$PROJECT_ROOT/.env.backup" --yes`                                |
| `site:rollback`    | `deployer help site:rollback`    | `deployer site:rollback --env="$ENV_FILE" --inventory="$INVENTORY_FILE"`                                                                                                                |
| `site:delete`      | `deployer help site:delete`      | `deployer site:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --force --yes`                                                                                 |
| `site:ssh`         | `deployer help site:ssh`         | `Not supported for AI agents (interactive terminal). Use server:run.`                                                                                                                   |

## Service Install Commands

| Command              | Reference                          | Complete non-interactive example                                                                                                                                                  |
| -------------------- | ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `mariadb:install`    | `deployer help mariadb:install`    | `deployer mariadb:install --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --display-credentials --save-credentials="$PROJECT_ROOT/.secrets/mariadb.txt"`       |
| `postgresql:install` | `deployer help postgresql:install` | `deployer postgresql:install --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --display-credentials --save-credentials="$PROJECT_ROOT/.secrets/postgresql.txt"` |
| `redis:install`      | `deployer help redis:install`      | `deployer redis:install --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --display-credentials --save-credentials="$PROJECT_ROOT/.secrets/redis.txt"`           |
| `memcached:install`  | `deployer help memcached:install`  | `deployer memcached:install --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                                                                                   |

## Service Lifecycle Commands

| Command              | Reference                          | Complete non-interactive example                                                                                       |
| -------------------- | ---------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| `nginx:start`        | `deployer help nginx:start`        | `deployer nginx:start --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                              |
| `nginx:stop`         | `deployer help nginx:stop`         | `deployer nginx:stop --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                               |
| `nginx:restart`      | `deployer help nginx:restart`      | `deployer nginx:restart --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                            |
| `php:start`          | `deployer help php:start`          | `deployer php:start --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --php-version="$PHP_VERSION"`   |
| `php:stop`           | `deployer help php:stop`           | `deployer php:stop --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --php-version="$PHP_VERSION"`    |
| `php:restart`        | `deployer help php:restart`        | `deployer php:restart --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --php-version="$PHP_VERSION"` |
| `mariadb:start`      | `deployer help mariadb:start`      | `deployer mariadb:start --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                            |
| `mariadb:stop`       | `deployer help mariadb:stop`       | `deployer mariadb:stop --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                             |
| `mariadb:restart`    | `deployer help mariadb:restart`    | `deployer mariadb:restart --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                          |
| `postgresql:start`   | `deployer help postgresql:start`   | `deployer postgresql:start --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                         |
| `postgresql:stop`    | `deployer help postgresql:stop`    | `deployer postgresql:stop --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                          |
| `postgresql:restart` | `deployer help postgresql:restart` | `deployer postgresql:restart --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                       |
| `redis:start`        | `deployer help redis:start`        | `deployer redis:start --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                              |
| `redis:stop`         | `deployer help redis:stop`         | `deployer redis:stop --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                               |
| `redis:restart`      | `deployer help redis:restart`      | `deployer redis:restart --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                            |
| `memcached:start`    | `deployer help memcached:start`    | `deployer memcached:start --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                          |
| `memcached:stop`     | `deployer help memcached:stop`     | `deployer memcached:stop --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                           |
| `memcached:restart`  | `deployer help memcached:restart`  | `deployer memcached:restart --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                        |
| `supervisor:start`   | `deployer help supervisor:start`   | `deployer supervisor:start --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                         |
| `supervisor:stop`    | `deployer help supervisor:stop`    | `deployer supervisor:stop --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                          |
| `supervisor:restart` | `deployer help supervisor:restart` | `deployer supervisor:restart --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER"`                       |

## Cron Commands

| Command       | Reference                   | Complete non-interactive example                                                                                                              |
| ------------- | --------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------- |
| `cron:create` | `deployer help cron:create` | `deployer cron:create --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --script="$CRON_SCRIPT" --schedule="$CRON_SCHEDULE"` |
| `cron:delete` | `deployer help cron:delete` | `deployer cron:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --script="$CRON_SCRIPT" --force --yes`               |
| `cron:sync`   | `deployer help cron:sync`   | `deployer cron:sync --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN"`                                                       |

## Supervisor Program Commands

| Command             | Reference                         | Complete non-interactive example                                                                                                                                                                                         |
| ------------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `supervisor:create` | `deployer help supervisor:create` | `deployer supervisor:create --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --program="$SUPERVISOR_PROGRAM" --script="$SUPERVISOR_SCRIPT" --autostart --autorestart --stopwaitsecs=3600 --numprocs=1` |
| `supervisor:delete` | `deployer help supervisor:delete` | `deployer supervisor:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN" --program="$SUPERVISOR_PROGRAM" --force --yes`                                                                            |
| `supervisor:sync`   | `deployer help supervisor:sync`   | `deployer supervisor:sync --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --domain="$DOMAIN"`                                                                                                                            |

## AWS Commands

| Command          | Reference                      | Complete non-interactive example                                                                                                                                                                                                                                                            |
| ---------------- | ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `aws:key:add`    | `deployer help aws:key:add`    | `deployer aws:key:add --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --name="$AWS_KEY_PAIR" --public-key-path="$SSH_PUBLIC_KEY"`                                                                                                                                                           |
| `aws:key:list`   | `deployer help aws:key:list`   | `deployer aws:key:list --env="$ENV_FILE" --inventory="$INVENTORY_FILE"`                                                                                                                                                                                                                     |
| `aws:key:delete` | `deployer help aws:key:delete` | `deployer aws:key:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --key="$AWS_KEY_PAIR" --force --yes`                                                                                                                                                                               |
| `aws:provision`  | `deployer help aws:provision`  | `deployer aws:provision --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --name="$SERVER" --instance-type="$AWS_INSTANCE_TYPE" --image="$AWS_IMAGE" --key-pair="$AWS_KEY_PAIR" --private-key-path="$SSH_PRIVATE_KEY" --vpc="$AWS_VPC" --subnet="$AWS_SUBNET" --disk-size=20 --no-monitoring` |
| `aws:dns:set`    | `deployer help aws:dns:set`    | `deployer aws:dns:set --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$AWS_ZONE" --type="A" --name="@" --value="$HOST" --ttl=300`                                                                                                                                                   |
| `aws:dns:list`   | `deployer help aws:dns:list`   | `deployer aws:dns:list --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$AWS_ZONE" --type="A"`                                                                                                                                                                                       |
| `aws:dns:delete` | `deployer help aws:dns:delete` | `deployer aws:dns:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$AWS_ZONE" --type="A" --name="@" --force --yes`                                                                                                                                                            |

## DigitalOcean Commands

| Command         | Reference                     | Complete non-interactive example                                                                                                                                                                                                                                                    |
| --------------- | ----------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `do:key:add`    | `deployer help do:key:add`    | `deployer do:key:add --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --name="$DO_KEY_NAME" --public-key-path="$SSH_PUBLIC_KEY"`                                                                                                                                                     |
| `do:key:list`   | `deployer help do:key:list`   | `deployer do:key:list --env="$ENV_FILE" --inventory="$INVENTORY_FILE"`                                                                                                                                                                                                              |
| `do:key:delete` | `deployer help do:key:delete` | `deployer do:key:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --key="$DO_SSH_KEY_ID" --force --yes`                                                                                                                                                                       |
| `do:provision`  | `deployer help do:provision`  | `deployer do:provision --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --name="$SERVER" --region="$DO_REGION" --image="$DO_IMAGE" --private-key-path="$SSH_PRIVATE_KEY" --size="$DO_SIZE" --ssh-key-id="$DO_SSH_KEY_ID" --vpc-uuid="$DO_VPC_UUID" --no-backups --ipv6 --monitoring` |
| `do:dns:set`    | `deployer help do:dns:set`    | `deployer do:dns:set --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$DO_ZONE" --type="A" --name="@" --value="$HOST" --ttl=300`                                                                                                                                             |
| `do:dns:list`   | `deployer help do:dns:list`   | `deployer do:dns:list --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$DO_ZONE" --type="A"`                                                                                                                                                                                 |
| `do:dns:delete` | `deployer help do:dns:delete` | `deployer do:dns:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$DO_ZONE" --type="A" --name="@" --force --yes`                                                                                                                                                      |

## Cloudflare DNS Commands

| Command         | Reference                     | Complete non-interactive example                                                                                                                  |
| --------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------- |
| `cf:dns:set`    | `deployer help cf:dns:set`    | `deployer cf:dns:set --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$CF_ZONE" --type="A" --name="@" --value="$HOST" --ttl=300 --proxied` |
| `cf:dns:list`   | `deployer help cf:dns:list`   | `deployer cf:dns:list --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$CF_ZONE" --type="A"`                                               |
| `cf:dns:delete` | `deployer help cf:dns:delete` | `deployer cf:dns:delete --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --zone="$CF_ZONE" --type="A" --name="@" --force --yes`                    |

## Safe Diagnostic Commands In Admin Tier

Use `server:run` for diagnostics while staying non-interactive:

```bash
deployer server:run --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --command="systemctl status nginx --no-pager"
deployer server:run --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --command="df -h"
deployer server:run --env="$ENV_FILE" --inventory="$INVENTORY_FILE" --server="$SERVER" --command="tail -n 200 /home/deployer/$DOMAIN/shared/storage/logs/laravel.log"
```

## Suggested Execution Order (Greenfield)

1. `scaffold:scripts`
2. `server:add`
3. `server:install`
4. `site:create`
5. `site:deploy`
6. `site:https`
7. `site:shared:push`
8. `cron:create` + `cron:sync` (if needed)
9. `supervisor:create` + `supervisor:sync` (if needed)
