# Documentation

DeployerPHP documentation is organized as a progressive guide.

Everything you need to deploy PHP applications:

<a name="guides"></a>

## Guides

- [Introduction](../README.md)
- [Installation](installation.md)
- [Zero to Deploy](zero-to-deploy.md)

<a name="references"></a>

## References

- [Managing Sites](managing-sites.md)
- [Managing Servers](managing-servers.md)
- [Managing Services](managing-services.md)
- [Managing Databases](managing-databases.md)
- [Cloud Providers](cloud-providers.md)
- [Automation & AI](automation.md)

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
