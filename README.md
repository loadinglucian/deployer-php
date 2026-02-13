<p align="center">
    <a href="https://deployerphp.com" target="_blank">
        <img src="https://raw.githubusercontent.com/loadinglucian/deployer-php/main/docs/images/logo-mark.svg" width="400" alt="DeployerPHP Logo">
    </a>
</p>

<p align="center">
    <a href="https://packagist.org/packages/loadinglucian/deployer-php"><img src="https://img.shields.io/badge/php-%5E8.2-blue.svg" alt="Supports PHP >= 8.2"></a>
    <a href="https://packagist.org/packages/loadinglucian/deployer-php"><img src="https://img.shields.io/packagist/v/loadinglucian/deployer-php" alt="Latest Stable Version"></a>
    <a href="https://packagist.org/packages/loadinglucian/deployer-php"><img src="https://img.shields.io/packagist/l/loadinglucian/deployer-php" alt="License"></a>
</p>

<p align="center">
    <a href="https://deployerphp.com/">https://deployerphp.com</a>
</p>

# Meet DeployerPHP

This is DeployerPHP, a super comprehensive set of CLI tools for provisioning, installing, and deploying servers and sites using PHP. It serves as an open-source alternative to services such as Ploi, RunCloud or Laravel Forge.

<p align="center">
    <img src="https://raw.githubusercontent.com/loadinglucian/deployer-php/main/docs/images/deployerphp.webp" width="auto" alt="Screenshot with DeployerPHP in action" class="dark:hidden">
    <img src="https://raw.githubusercontent.com/loadinglucian/deployer-php/main/docs/images/deployerphp-dark.webp" width="auto" alt="Screenshot with DeployerPHP in action" class="hidden dark:block">
</p>

<!-- toc -->

- [Crash Course](#crash-course)
- [Benefits](#benefits)
    - [Unlimited Servers & Sites](#unlimited-servers--sites)
    - [No Vendor Lock-In](#no-vendor-lock-in)
    - [End-To-End Management](#end-to-end-management)
    - [Composable Commands](#composable-commands)
    - [AI Automation](#ai-automation)
- [License](#license)
- [Contributing](#contributing)

<!-- /toc -->

<a name="crash-course"></a>

## Crash Course

Here's a quick run-through to start deploying immediately:

```shell
# 1. Install as a dev dependency
composer require --dev loadinglucian/deployer-php

# 2. Add an alias for convenience
alias deployer="./vendor/bin/deployer"

# 3. Add your server to the inventory
deployer server:add

# Alternatively, provision a cloud instance and add it
# to the inventory automatically with a single command
deployer aws:provision
deployer do:provision

# 4. Install Nginx, PHP, Bun and generate a deploy key
deployer server:install

# 5. Install your preferred database services
deployer mariadb:install
deployer postgresql:install
deployer redis:install
deployer memcached:install

# 6. Create a site
deployer site:create

# 7. Generate deployment scripts (run from your project directory)
deployer scaffold:scripts

# 8. Upload your .env file to the server
deployer site:shared:push

# 9. Deploy your application
deployer site:deploy

# 10. Enable HTTPS
deployer site:https
```

<a name="benefits"></a>

## Benefits

<a name="unlimited-servers--sites"></a>

### Unlimited Servers & Sites

There aren't any limits or restrictions on how many servers and sites you can deploy or manage: provision, install, manage, and deploy as many as you want.

<a name="no-vendor-lock-in"></a>

### No Vendor Lock-In

You can manage servers and deploy sites with any hosting or cloud provider. If your server runs Ubuntu LTS and you can SSH into it, you can manage it with DeployerPHP.

<a name="end-to-end-management"></a>

### End-To-End Management

With DeployerPHP, you can effortlessly provision cloud instances, install services, and manage deployments and operations directly from the command line.

<a name="composable-commands"></a>

### Composable Commands

Atomic commands allow you to easily spin up new servers and create automation pipelines for running your own custom workflows on demand.

<a name="ai-automation"></a>

### AI Agent Support

Use your favorite AI agents to debug server and site issues, using DeployerPHP's built-in agent skills scaffolding.

<a name="license"></a>

## License

DeployerPHP is open-source software distributed under the [MIT License](/LICENSE).

You can use it freely for personal or commercial projects, without any restrictions.

This also means there are no guarantees or warranties. You are on your own.

<a name="contributing"></a>

## Contributing

Thank you for considering contributing to DeployerPHP!

Please see [CONTRIBUTING](/CONTRIBUTING) for details.
