[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Read the documentation at [https://deployerphp.com](https://deployerphp.com/)

[Follow me on X](https://x.com/loadinglucian) for updates, memes and hot take.

# Meet DeployerPHP

This is DeployerPHP, a super comprehensive set of CLI tools for provisioning, installing, and deploying servers and sites using PHP. It serves as an open-source alternative to services such as Laravel Forge and Ploi.

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

Here's the crashiest of crash courses to start deploying immediately:

```shell
# 1. Install as a dev dependency
composer require --dev loadinglucian/deployer-php

# 2. Add an alias for convenience
alias deployer="./vendor/bin/deployer"

# 3. Add your server to the inventory
deployer server:add

# Or provision through a cloud provider to automatically
# add to your inventory (see docs/cloud-providers.md):
# deployer aws:provision

# 4. Install your preferred database service:
# deployer mariadb:install
# deployer postgresql:install
# deployer redis:install
# deployer memcached:install

# 5. Install Nginx, PHP, Bun and generate a deploy key
deployer server:install

# 6. Create a site
deployer site:create

# 7. Generate deployment scripts (run from your project directory)
deployer scaffold:scripts

# 8. Upload your .env file to the server
deployer site:shared:push

# 9. Deploy your application
deployer site:deploy

# 10. Enable HTTPS (after DNS propagates)
deployer site:https
```

<a name="benefits"></a>

## Benefits

<a name="unlimited-servers--sites"></a>

### Unlimited Servers & Sites

There aren't any limits or restrictions on how many servers and sites you can deploy or manage: provision, install, manage, and deploy as many as you want.

<a name="no-vendor-lock-in"></a>

### No Vendor Lock-In

You can manage servers and deploy sites with any hosting or cloud provider. If your server runs Ubuntu LTS and you can SSH into it, you can deploy sites there using DeployerPHP.

<a name="end-to-end-management"></a>

### End-To-End Management

With DeployerPHP, you can effortlessly provision cloud instances, install services, and manage deployments and operations directly from the command line.

<a name="composable-commands"></a>

### Composable Commands

Atomic commands allow you to easily create automation pipelines for spinning up servers, installing services, deploying sites, or running custom workflows on demand.

<a name="ai-automation"></a>

### AI Automation

Use your favorite AI agents to help you debug server and site issues, using DeployerPHP's composable commands and built-in agent skills. When you run `scaffold:ai`, choose either `.agents` (for Codex, Cursor, OpenCode, etc.) or `.claude`.

<a name="license"></a>

## License

DeployerPHP is open-source software distributed under the [MIT License](/LICENSE).

You can use it freely for personal or commercial projects, without any restrictions.

This also means there are no guarantees or warranties that apply. You are on your own.

<a name="contributing"></a>

## Contributing

Thank you for considering contributing to DeployerPHP!

Please see [CONTRIBUTING](/CONTRIBUTING) for details.
